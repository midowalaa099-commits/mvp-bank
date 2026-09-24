<?php

namespace Tests\Feature\Policies;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_only_admins_can_access_admin_routes(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'can:access-admin'])->get('/api/v1/test-admin', fn () => response()->noContent());
        $customer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse(Gate::forUser($customer)->allows('access-admin'));
        $this->assertTrue(Gate::forUser($admin)->allows('access-admin'));
        $this->getJson('/api/v1/test-admin')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($customer->createToken('test')->plainTextToken)->getJson('/api/v1/test-admin')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/v1/test-admin')->assertNoContent();
    }

    public function test_customer_cannot_view_another_account_even_with_an_all_abilities_token(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'can:view,account'])->get('/api/v1/test-accounts/{account}', fn (Account $account) => response()->noContent());
        $account = Account::factory()->create();
        $outsider = User::factory()->create();

        $this->withToken($outsider->createToken('test', ['*'])->plainTextToken)
            ->getJson('/api/v1/test-accounts/'.$account->reference)->assertNotFound();
    }

    public function test_account_creation_is_limited_to_customers(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->create())->allows('create', Account::class));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('create', Account::class));
    }

    public function test_demotion_removes_admin_access_from_an_existing_token(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'can:access-admin'])->get('/api/v1/test-admin', fn () => response()->noContent());
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test');
        $this->withToken($token->plainTextToken)->getJson('/api/v1/test-admin')->assertNoContent();

        $admin->role = UserRole::Customer;
        $admin->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/test-admin')->assertForbidden();
    }

    #[DataProvider('accountPermissions')]
    public function test_account_permission_matrix(string $ability, bool $ownerAllowed, bool $outsiderAllowed, bool $adminAllowed): void
    {
        $account = Account::factory()->create();
        $outsider = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertSame($ownerAllowed, Gate::forUser($account->user)->allows($ability, $account));
        $this->assertSame($outsiderAllowed, Gate::forUser($outsider)->allows($ability, $account));
        $this->assertSame($adminAllowed, Gate::forUser($admin)->allows($ability, $account));
    }

    /** @return array<string, array{string, bool, bool, bool}> */
    public static function accountPermissions(): array
    {
        return [
            'view' => ['view', true, false, true],
            'deposit' => ['deposit', true, false, false],
            'withdraw' => ['withdraw', true, false, false],
            'transfer' => ['transfer', true, false, false],
            'freeze' => ['freeze', false, false, true],
            'unfreeze' => ['unfreeze', false, false, true],
            'delete' => ['delete', false, false, false],
            'direct update' => ['update', false, false, false],
        ];
    }

    public function test_transaction_is_visible_to_participants_and_admin_only(): void
    {
        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();
        $transaction = Transaction::factory()->transfer($sender, $recipient)->create();
        $outsider = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($sender->user)->allows('view', $transaction));
        $this->assertTrue(Gate::forUser($recipient->user)->allows('view', $transaction));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $transaction));
        $this->assertSame(404, Gate::forUser($outsider)->inspect('view', $transaction)->status());
        $this->assertFalse(Gate::forUser($admin)->allows('update', $transaction));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $transaction));
    }
}
