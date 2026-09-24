<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => TransactionType::Deposit,
            'source_account_id' => null,
            'destination_account_id' => Account::factory(),
            'initiated_by' => fn (array $attributes): int => Account::findOrFail($attributes['destination_account_id'])->user_id,
            'currency_id' => fn (array $attributes): int => Account::findOrFail($attributes['destination_account_id'])->currency_id,
            'amount_minor' => 1000,
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function deposit(Account $destination): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => TransactionType::Deposit,
            'source_account_id' => null,
            'destination_account_id' => $destination->id,
            'initiated_by' => $destination->user_id,
            'currency_id' => $destination->currency_id,
        ]);
    }

    public function withdrawal(Account $source): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => TransactionType::Withdrawal,
            'source_account_id' => $source->id,
            'destination_account_id' => null,
            'initiated_by' => $source->user_id,
            'currency_id' => $source->currency_id,
        ]);
    }

    public function transfer(Account $source, Account $destination): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => TransactionType::Transfer,
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'initiated_by' => $source->user_id,
            'currency_id' => $source->currency_id,
        ]);
    }
}
