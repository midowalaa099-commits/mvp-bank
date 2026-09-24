<?php

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Currency $currency): void {
            if ($currency->isDirty(['code', 'decimal_places']) && $currency->accounts()->exists()) {
                throw new LogicException('A currency in use cannot change its code or decimal precision.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
        ];
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
