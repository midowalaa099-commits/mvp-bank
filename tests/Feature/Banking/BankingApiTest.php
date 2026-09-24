<?php

namespace Tests\Feature\Banking;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BankingApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_opens_zero_balance_account_and_cannot_open_a_duplicate(): void
    {
        $user = User::factory()->create();
        $this->asUser($user);

        $response = $this->postJson('/api/v1/accounts', ['currency' => 'egp']);
        $response->assertCreated()->assertJsonPath('data.currency', 'EGP')->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.user_id');
        $this->assertSame($user->id, Account::sole()->user_id);
        $this->postJson('/api/v1/accounts', ['currency' => 'EGP'])->assertConflict();
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_account_creation_rejects_unsupported_currency_and_injected_owner_or_balance(): void
    {
        $this->asUser(User::factory()->create());
        $this->postJson('/api/v1/accounts', ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/accounts', ['currency' => 'EGP', 'user_id' => 123, 'balance_minor' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'balance_minor']);
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_account_lists_and_details_are_scoped_to_the_owner(): void
    {
        $own = Account::factory()->create();
        $other = Account::factory()->create();
        $this->asUser($own->user);

        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $own->reference);
        $this->getJson('/api/v1/accounts/'.$own->reference)->assertOk();
        $this->getJson('/api/v1/accounts/'.$other->reference)->assertNotFound();
        $this->getJson('/api/v1/accounts/'.$other->reference.'/transactions')->assertNotFound();
        $this->patchJson('/api/v1/accounts/'.$own->reference, ['balance' => '999.00'])->assertMethodNotAllowed();
    }

    public function test_deposit_withdrawal_and_transfer_update_balances_and_history(): void
    {
        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();
        $this->asUser($sender->user);

        $this->operation($sender, 'deposits', '20.25', 'deposit-1')->assertCreated()->assertJsonPath('data.amount', '20.25');
        $this->operation($sender, 'withdrawals', '2.25', 'withdraw-1')->assertCreated();
        $transfer = $this->operation($sender, 'transfers', '7.50', 'transfer-1', $recipient)->assertCreated();

        $this->assertSame(1050, $sender->fresh()->balance_minor);
        $this->assertSame(750, $recipient->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 3);
        $this->getJson('/api/v1/accounts/'.$sender->reference)->assertJsonPath('data.balance', '10.50');
        $this->getJson('/api/v1/accounts/'.$sender->reference.'/transactions')->assertJsonCount(3, 'data');

        $this->asUser($recipient->user);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $transfer->json('data.reference'));
        $this->getJson('/api/v1/transactions/'.$transfer->json('data.reference'))->assertOk()
            ->assertJsonMissingPath('data.balance')->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.request_hash');
        $this->getJson('/api/v1/accounts/'.$sender->reference)->assertNotFound();

        $this->asUser(User::factory()->create());
        $this->getJson('/api/v1/transactions')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/transactions/'.$transfer->json('data.reference'))->assertNotFound();
    }

    public function test_replaying_equivalent_amount_returns_original_transaction_even_after_freeze(): void
    {
        $account = Account::factory()->create();
        $this->asUser($account->user);
        $first = $this->operation($account, 'deposits', '10.0', 'same-key')->assertCreated();
        $account->status = AccountStatus::Frozen;
        $account->save();

        $retry = $this->operation($account, 'deposits', '10.00', 'same-key')->assertOk();

        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertSame(1000, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_reusing_a_key_with_another_amount_or_operation_returns_409(): void
    {
        $account = Account::factory()->create();
        $this->asUser($account->user);
        $this->operation($account, 'deposits', '10.00', 'same-key')->assertCreated();

        $this->operation($account, 'deposits', '11.00', 'same-key')->assertConflict();
        $this->operation($account, 'withdrawals', '10.00', 'same-key')->assertConflict();
        $this->assertSame(1000, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_retrying_transfer_does_not_move_funds_twice_and_changed_destination_conflicts(): void
    {
        $sender = Account::factory()->create(['balance_minor' => 1000]);
        $recipient = Account::factory()->create();
        $other = Account::factory()->create();
        $this->asUser($sender->user);
        $first = $this->operation($sender, 'transfers', '5.00', 'same-key', $recipient)->assertCreated();

        $this->operation($sender, 'transfers', '5', 'same-key', $recipient)->assertOk()->assertJsonPath('data.reference', $first->json('data.reference'));
        $this->operation($sender, 'transfers', '5.00', 'same-key', $other)->assertConflict();
        $this->assertSame(500, $sender->fresh()->balance_minor);
        $this->assertSame(500, $recipient->fresh()->balance_minor);
        $this->assertSame(0, $other->fresh()->balance_minor);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_leaves_balance_and_history_unchanged(mixed $amount): void
    {
        $account = Account::factory()->create();
        $this->asUser($account->user);

        $this->operation($account, 'deposits', $amount, 'request-key')->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(0, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
    }

    public static function invalidAmounts(): array
    {
        return [[1.25], [true], [['1.25']], ['0'], ['-1'], ['1e2'], ['0.001'], ['92233720368547758.08']];
    }

    public function test_money_requests_require_a_valid_header_key_and_cannot_override_currency(): void
    {
        $account = Account::factory()->create();
        $this->asUser($account->user);
        $url = '/api/v1/accounts/'.$account->reference.'/deposits';

        $this->postJson($url, ['amount' => '1.00', 'idempotency_key' => 'body-key'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->operation($account, 'deposits', '1', 'bad key')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->withHeader('Idempotency-Key', 'good-key')->postJson($url, ['amount' => '1.00', 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_insufficient_balance_and_overflow_leave_both_accounts_unchanged(): void
    {
        $sender = Account::factory()->create(['balance_minor' => 100]);
        $recipient = Account::factory()->create(['balance_minor' => PHP_INT_MAX]);
        $this->asUser($sender->user);

        $this->operation($sender, 'withdrawals', '1.01', 'withdraw')->assertConflict();
        $this->operation($sender, 'transfers', '1.01', 'transfer', $recipient)->assertConflict();
        $this->operation($sender, 'transfers', '0.01', 'overflow', $recipient)->assertConflict();
        $this->assertSame(100, $sender->fresh()->balance_minor);
        $this->assertSame(PHP_INT_MAX, $recipient->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_frozen_accounts_and_cross_currency_transfers_are_rejected(): void
    {
        $sender = Account::factory()->create(['balance_minor' => 1000]);
        $frozen = Account::factory()->frozen()->create();
        $usd = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);
        $foreign = Account::factory()->for($usd)->create();
        $this->asUser($sender->user);

        $this->operation($sender, 'transfers', '1.00', 'frozen', $frozen)->assertConflict();
        $this->operation($sender, 'transfers', '1.00', 'foreign', $foreign)->assertUnprocessable()->assertJsonValidationErrors('destination_account');
        $this->operation($sender, 'transfers', '1.00', 'self', $sender)->assertUnprocessable();
        $this->asUser($frozen->user);
        $this->operation($frozen, 'deposits', '1.00', 'deposit')->assertConflict();
        $this->operation($frozen, 'withdrawals', '1.00', 'withdraw')->assertConflict();
        $this->operation($frozen, 'transfers', '1.00', 'send', $sender)->assertConflict();
        $this->assertSame(1000, $sender->fresh()->balance_minor);
        $this->assertSame(0, $frozen->fresh()->balance_minor);
        $this->assertSame(0, $foreign->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_other_customers_and_admins_cannot_move_customer_money(): void
    {
        $account = Account::factory()->create();
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->asUser($user);
            foreach (['deposits', 'withdrawals', 'transfers'] as $operation) {
                $this->operation($account, $operation, '1.00', 'key')->assertNotFound();
            }
        }
        $this->postJson('/api/v1/accounts', ['currency' => 'EGP'])->assertForbidden();
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(0, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_failed_history_insert_rolls_back_both_balance_changes(): void
    {
        $sender = Account::factory()->create(['balance_minor' => 1000]);
        $recipient = Account::factory()->create();
        $this->asUser($sender->user);
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.Transaction::class, function (): void {
            throw new RuntimeException('History insert failed.');
        });

        try {
            $this->operation($sender, 'transfers', '7.00', 'rollback', $recipient);
            $this->fail('Expected history insertion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('History insert failed.', $exception->getMessage());
        }

        $this->assertSame(1000, $sender->fresh()->balance_minor);
        $this->assertSame(0, $recipient->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_account_identity_and_used_currency_precision_cannot_change(): void
    {
        $account = Account::factory()->create();
        $account->reference = 'replacement';
        try {
            $account->save();
            $this->fail('Expected identity change to fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be changed', $exception->getMessage());
        }
        $currency = $account->currency;
        $currency->decimal_places = 3;
        $this->expectException(LogicException::class);
        $currency->save();
    }

    public function test_banking_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/accounts')->assertUnauthorized();
        $this->postJson('/api/v1/accounts', ['currency' => 'EGP'])->assertUnauthorized();
        $this->getJson('/api/v1/transactions')->assertUnauthorized();
    }

    private function asUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function operation(Account $account, string $operation, mixed $amount, string $key, ?Account $destination = null): TestResponse
    {
        $data = ['amount' => $amount];
        if ($destination) {
            $data['destination_account'] = $destination->reference;
        }

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/accounts/'.$account->reference.'/'.$operation, $data);
    }
}
