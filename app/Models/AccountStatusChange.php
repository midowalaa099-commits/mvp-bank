<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccountStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (AccountStatusChange $change): void {
            throw new LogicException('Status history cannot be modified.');
        });
        static::deleting(function (AccountStatusChange $change): void {
            throw new LogicException('Status history cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_status' => AccountStatus::class, 'to_status' => AccountStatus::class, 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
