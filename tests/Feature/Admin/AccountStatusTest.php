<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AccountStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_freeze_and_unfreeze_with_audit_history(): void
    {
        $account = Account::factory()->create(['balance_minor' => 1000]);
        $admin = User::factory()->admin()->create();
        $this->withToken($admin->createToken('test')->plainTextToken);
        $base = '/api/v1/admin/accounts/'.$account->reference;

        $this->postJson($base.'/freeze', ['reason' => 'Customer requested a temporary freeze.'])
            ->assertOk()->assertJsonPath('data.status', 'frozen');
        $this->postJson($base.'/freeze', ['reason' => 'Retry of the same freeze.'])->assertOk();
        $this->assertDatabaseCount('account_status_changes', 1);
        $this->postJson($base.'/unfreeze', ['reason' => 'Customer confirmed the account can reopen.'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson($base.'/status-changes')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.to_status', 'active')->assertJsonPath('data.0.performed_by.id', $admin->id)
            ->assertJsonMissingPath('data.0.performed_by.password');

        $this->assertSame(1000, $account->fresh()->balance_minor);
        $this->assertDatabaseHas('account_status_changes', ['account_id' => $account->id, 'performed_by' => $admin->id, 'from_status' => 'active', 'to_status' => 'frozen']);
    }

    public function test_guests_and_customers_cannot_change_status_or_read_admin_history(): void
    {
        $account = Account::factory()->create();
        $base = '/api/v1/admin/accounts/'.$account->reference;
        $this->postJson($base.'/freeze', ['reason' => 'Requested freeze'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($account->user->createToken('test')->plainTextToken);
        $this->postJson($base.'/freeze', ['reason' => 'Requested freeze'])->assertForbidden();
        $this->postJson($base.'/unfreeze', ['reason' => 'Requested unfreeze'])->assertForbidden();
        $this->getJson($base.'/status-changes')->assertForbidden();
        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertDatabaseCount('account_status_changes', 0);
    }

    public function test_reason_is_required_and_bounded(): void
    {
        $account = Account::factory()->create();
        $this->withToken(User::factory()->admin()->create()->createToken('test')->plainTextToken);
        foreach (['', 'ab', str_repeat('x', 501)] as $reason) {
            $this->postJson('/api/v1/admin/accounts/'.$account->reference.'/freeze', ['reason' => $reason])
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertFalse($account->fresh()->isFrozen());
        $this->assertDatabaseCount('account_status_changes', 0);
    }

    public function test_freeze_blocks_money_and_unfreeze_restores_access(): void
    {
        $account = Account::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->withToken($admin->createToken('test')->plainTextToken)
            ->postJson('/api/v1/admin/accounts/'.$account->reference.'/freeze', ['reason' => 'Temporary review'])->assertOk();
        $this->app['auth']->forgetGuards();
        $customerToken = $account->user->createToken('test')->plainTextToken;
        $this->withToken($customerToken)->withHeader('Idempotency-Key', 'blocked-deposit')
            ->postJson('/api/v1/accounts/'.$account->reference.'/deposits', ['amount' => '1.00'])->assertConflict();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('test')->plainTextToken)
            ->postJson('/api/v1/admin/accounts/'.$account->reference.'/unfreeze', ['reason' => 'Review complete'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($customerToken)->postJson('/api/v1/accounts/'.$account->reference.'/deposits', ['amount' => '1.00'])->assertCreated();
        $this->assertSame(100, $account->fresh()->balance_minor);
    }

    public function test_status_change_rolls_back_when_audit_insert_fails(): void
    {
        $account = Account::factory()->create();
        $this->withToken(User::factory()->admin()->create()->createToken('test')->plainTextToken);
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AccountStatusChange::class, function (): void {
            throw new RuntimeException('Audit unavailable.');
        });
        try {
            $this->postJson('/api/v1/admin/accounts/'.$account->reference.'/freeze', ['reason' => 'Temporary review']);
            $this->fail('Expected audit creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable.', $exception->getMessage());
        }
        $this->assertFalse($account->fresh()->isFrozen());
        $this->assertDatabaseCount('account_status_changes', 0);
    }
}
