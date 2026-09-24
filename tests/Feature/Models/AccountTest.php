<?php

namespace Tests\Feature\Models;

use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Database\Eloquent\MassAssignmentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountTest extends TestCase
{
    #[DataProvider('protectedAttributes')]
    public function test_input_cannot_overwrite_protected_account_fields(string $attribute, mixed $value): void
    {
        $account = new Account;

        $this->expectException(MassAssignmentException::class);

        $account->fill([$attribute => $value]);
    }

    public function test_account_starts_active_and_recognizes_a_freeze(): void
    {
        $account = new Account;
        $this->assertFalse($account->isFrozen());

        $account->status = AccountStatus::Frozen;

        $this->assertTrue($account->isFrozen());
    }

    /** @return array<string, array{string, mixed}> */
    public static function protectedAttributes(): array
    {
        return [
            'balance' => ['balance_minor', 999999],
            'owner' => ['user_id', 99],
            'currency' => ['currency_id', 99],
            'status' => ['status', 'active'],
            'reference' => ['reference', 'forged-reference'],
        ];
    }
}
