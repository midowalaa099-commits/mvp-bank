<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AccountStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccountStatusService
{
    public function change(User $user, Account $account, AccountStatus $status, string $reason): Account
    {
        return DB::transaction(function () use ($user, $account, $status, $reason): Account {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('access-admin');
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize($status === AccountStatus::Frozen ? 'freeze' : 'unfreeze', $locked);

            if ($locked->status !== $status) {
                $change = new AccountStatusChange;
                $change->account_id = $locked->id;
                $change->performed_by = $actor->id;
                $change->from_status = $locked->status;
                $change->to_status = $status;
                $change->reason = $reason;
                $change->save();

                $locked->status = $status;
                $locked->save();
            }

            return $locked->load('currency');
        }, 3);
    }
}
