<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency_id' => fn (): int => Currency::query()->where('code', 'EGP')->firstOrFail()->id,
            'balance_minor' => 0,
            'status' => AccountStatus::Active,
        ];
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AccountStatus::Frozen]);
    }
}
