<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'balance_minor' => 0,
        'status' => AccountStatus::Active->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (Account $account): void {
            $account->reference ??= (string) Str::uuid();
        });

        static::updating(function (Account $account): void {
            if ($account->isDirty(['user_id', 'currency_id', 'reference'])) {
                throw new LogicException('Account ownership, currency, and reference cannot be changed.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'status' => AccountStatus::class,
        ];
    }

    public function isFrozen(): bool
    {
        return $this->status === AccountStatus::Frozen;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function outgoingTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_account_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function incomingTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'destination_account_id');
    }

    /** @return HasMany<AccountStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(AccountStatusChange::class);
    }
}
