<?php

namespace Tests\Feature\Banking;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountStatusService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentOperationsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_withdrawals_cannot_spend_the_same_balance(): void
    {
        $account = Account::factory()->create(['balance_minor' => 1000]);
        $payload = ['user_id' => $account->user_id, 'account_id' => $account->id, 'operation' => 'withdrawal', 'amount' => '7.00'];

        $results = $this->runTogether($account, [
            $payload + ['key' => 'withdraw-a'], $payload + ['key' => 'withdraw-b'],
        ]);

        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertSame(300, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_simultaneous_retries_create_one_deposit(): void
    {
        $account = Account::factory()->create();
        $payload = ['user_id' => $account->user_id, 'account_id' => $account->id, 'operation' => 'deposit', 'amount' => '7.00', 'key' => 'same-key'];

        $results = $this->runTogether($account, [$payload, $payload]);

        $this->assertSame([200, 201], $this->statuses($results));
        $this->assertSame($results[0]['reference'], $results[1]['reference']);
        $this->assertSame(700, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_opposing_transfers_complete_without_losing_money(): void
    {
        $first = Account::factory()->create(['balance_minor' => 1000]);
        $second = Account::factory()->create(['balance_minor' => 1000]);

        $results = $this->runTogether($first, [
            ['user_id' => $first->user_id, 'account_id' => $first->id, 'operation' => 'transfer', 'amount' => '7.00', 'key' => 'first', 'destination' => $second->reference],
            ['user_id' => $second->user_id, 'account_id' => $second->id, 'operation' => 'transfer', 'amount' => '7.00', 'key' => 'second', 'destination' => $first->reference],
        ]);

        $this->assertSame([201, 201], $this->statuses($results));
        $this->assertSame(1000, $first->fresh()->balance_minor);
        $this->assertSame(1000, $second->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_simultaneous_account_opening_creates_only_one_account(): void
    {
        $user = User::factory()->create();
        $payload = ['user_id' => $user->id, 'operation' => 'open'];

        $results = $this->runTogether($user, [$payload, $payload]);

        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_waiting_money_requests_observe_a_freeze_before_they_resume(): void
    {
        $account = Account::factory()->create();
        $admin = User::factory()->admin()->create();
        $payload = ['user_id' => $account->user_id, 'account_id' => $account->id, 'operation' => 'deposit', 'amount' => '7.00'];

        $results = $this->runTogether($account, [$payload + ['key' => 'one'], $payload + ['key' => 'two']], function () use ($admin, $account): void {
            $this->app->make(AccountStatusService::class)->change($admin, $account, AccountStatus::Frozen, 'Freeze before waiting requests resume.');
        });

        $this->assertSame([409, 409], $this->statuses($results));
        $this->assertTrue($account->fresh()->isFrozen());
        $this->assertSame(0, $account->fresh()->balance_minor);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('account_status_changes', 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function runTogether(Model $record, array $payloads, ?Closure $beforeRelease = null): array
    {
        $processes = [];
        DB::beginTransaction();
        $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

        try {
            foreach ($payloads as $payload) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/bank_worker.php'), json_encode($payload, JSON_THROW_ON_ERROR)], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => 'mvp_bank_test',
                    'DB_URL' => '',
                    'DB_HOST' => config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_SOCKET' => config('database.connections.mysql.unix_socket'),
                    'DB_USERNAME' => config('database.connections.mysql.username'),
                    'DB_PASSWORD' => config('database.connections.mysql.password'),
                    'CACHE_STORE' => 'array',
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne(<<<'SQL'
                    SELECT COUNT(DISTINCT waits.REQUESTING_ENGINE_TRANSACTION_ID) AS waiting_count
                    FROM performance_schema.data_lock_waits AS waits
                    JOIN performance_schema.data_locks AS locks
                        ON waits.REQUESTING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
                    WHERE locks.OBJECT_SCHEMA = 'mvp_bank_test'
                    SQL)->waiting_count;
                if ((int) $waiting >= 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);

            $this->assertGreaterThanOrEqual(2, (int) $waiting, 'Both worker transactions must overlap and wait for locks.');
            $beforeRelease?->__invoke();
            DB::commit();

            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /** @return array<int, int> */
    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }
}
