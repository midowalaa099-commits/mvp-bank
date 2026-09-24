<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Transaction $transaction): Response
    {
        return $user->isAdmin()
            || $transaction->sourceAccount?->user_id === $user->id
            || $transaction->destinationAccount?->user_id === $user->id
                ? Response::allow()
                : Response::denyAsNotFound();
    }
}
