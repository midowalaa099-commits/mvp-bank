<?php

namespace Tests\Feature\Database;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_seeding_is_repeatable_and_creates_only_egp_without_login_accounts(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('currencies', 1);
        $this->assertDatabaseHas('currencies', ['code' => 'EGP', 'decimal_places' => 2]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_user_account_and_transaction_relationships_load_saved_records(): void
    {
        $sender = Account::factory()->create();
        $recipient = Account::factory()->create();
        $transaction = Transaction::factory()->transfer($sender, $recipient)->create();

        $this->assertTrue($sender->user->accounts->contains($sender));
        $this->assertTrue($sender->currency->accounts->contains($recipient));
        $this->assertTrue($sender->currency->transactions->contains($transaction));
        $this->assertTrue($sender->user->initiatedTransactions->contains($transaction));
        $this->assertTrue($sender->outgoingTransactions->contains($transaction));
        $this->assertTrue($recipient->incomingTransactions->contains($transaction));
        $this->assertTrue($transaction->sourceAccount->is($sender));
        $this->assertTrue($transaction->destinationAccount->is($recipient));
        $this->assertTrue($transaction->initiator->is($sender->user));
        $this->assertTrue($transaction->currency->is($sender->currency));
        $this->assertSame(TransactionType::Transfer, $transaction->fresh()->type);
        $this->assertSame(UserRole::Customer, $sender->user->role);
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
        $this->assertSame(0, $sender->fresh()->balance_minor);
    }

    public function test_customer_can_have_different_currencies_but_only_one_account_per_currency(): void
    {
        $egpAccount = Account::factory()->create();
        $usd = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);
        Account::factory()->for($egpAccount->user)->for($usd)->create();
        $this->assertSame(2, $egpAccount->user->accounts()->count());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_user_id_currency_id_unique');

        Account::factory()->for($egpAccount->user)->create();
    }

    public function test_database_rejects_negative_balances(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_balance_nonnegative');

        DB::table('accounts')->where('id', $account->id)->update(['balance_minor' => -1]);
    }

    public function test_large_balances_and_amounts_round_trip_without_losing_precision(): void
    {
        $account = Account::factory()->create(['balance_minor' => 9007199254740993]);
        $transaction = Transaction::factory()->deposit($account)->create(['amount_minor' => 9007199254740993]);

        $this->assertSame(9007199254740993, $account->fresh()->balance_minor);
        $this->assertSame(9007199254740993, $transaction->fresh()->amount_minor);
    }

    public function test_database_rejects_duplicate_public_account_references(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_reference_unique');

        Account::factory()->create(['reference' => $account->reference]);
    }

    public function test_database_rejects_duplicate_public_transaction_references(): void
    {
        $transaction = Transaction::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_reference_unique');

        Transaction::factory()->create(['reference' => $transaction->reference]);
    }

    public function test_database_rejects_a_nonexistent_account_reference(): void
    {
        $transaction = Transaction::factory()->make();
        $transaction->destination_account_id = 999999;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_destination_currency_fk');

        $transaction->save();
    }

    #[DataProvider('invalidTransactionFields')]
    public function test_database_rejects_invalid_transaction_fields(array $changes, string $constraint): void
    {
        $transaction = Transaction::factory()->make();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($constraint);

        $transaction->forceFill($changes)->save();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidTransactionFields(): array
    {
        return [
            'zero amount' => [['amount_minor' => 0], 'transactions_amount_positive'],
            'negative amount' => [['amount_minor' => -1], 'transactions_amount_positive'],
            'deposit without destination' => [['destination_account_id' => null], 'transactions_valid_accounts'],
            'withdrawal with deposit shape' => [['type' => 'withdrawal'], 'transactions_valid_accounts'],
            'transfer without source' => [['type' => 'transfer'], 'transactions_valid_accounts'],
        ];
    }

    public function test_database_rejects_transfer_to_the_same_account(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_valid_accounts');

        Transaction::factory()->transfer($account, $account)->create();
    }

    #[DataProvider('currencyMismatchSides')]
    public function test_database_rejects_a_currency_mismatch_on_either_account(string $side): void
    {
        $egp = Account::factory()->create();
        $usd = Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);
        $usdAccount = Account::factory()->for($usd)->create();
        $transaction = Transaction::factory()->transfer($egp, $usdAccount)->make();
        $transaction->currency_id = $side === 'source' ? $usd->id : $egp->currency_id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_'.$side.'_currency_fk');

        $transaction->save();
    }

    /** @return array<string, array{string}> */
    public static function currencyMismatchSides(): array
    {
        return ['source' => ['source'], 'destination' => ['destination']];
    }

    public function test_request_key_can_be_reused_by_another_customer_but_not_the_same_customer(): void
    {
        $first = Transaction::factory()->create(['idempotency_key' => 'request-123']);
        Transaction::factory()->create(['idempotency_key' => 'request-123']);
        $this->assertDatabaseCount('transactions', 2);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_initiated_by_idempotency_key_unique');

        Transaction::factory()->deposit($first->destinationAccount)->create(['idempotency_key' => 'request-123']);
    }

    public function test_request_keys_are_case_sensitive(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->deposit($account)->create(['idempotency_key' => 'Request-123']);
        Transaction::factory()->deposit($account)->create(['idempotency_key' => 'request-123']);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_account_with_history_cannot_be_deleted(): void
    {
        $transaction = Transaction::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('transactions_destination_currency_fk');

        $transaction->destinationAccount->delete();
    }

    public function test_user_with_an_account_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_user_id_foreign');

        $account->user->delete();
    }

    public function test_deposit_and_withdrawal_keep_the_correct_account_links(): void
    {
        $account = Account::factory()->frozen()->create();
        $deposit = Transaction::factory()->deposit($account)->create();
        $withdrawal = Transaction::factory()->withdrawal($account)->create();

        $this->assertTrue($account->fresh()->isFrozen());
        $this->assertNull($deposit->fresh()->sourceAccount);
        $this->assertTrue($deposit->destinationAccount->is($account));
        $this->assertTrue($withdrawal->sourceAccount->is($account));
        $this->assertNull($withdrawal->fresh()->destinationAccount);
    }
}
