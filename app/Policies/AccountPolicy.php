<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Account $account): Response
    {
        return $user->isAdmin() || $account->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function deposit(User $user, Account $account): Response
    {
        return $this->operate($user, $account);
    }

    public function withdraw(User $user, Account $account): Response
    {
        return $this->operate($user, $account);
    }

    public function transfer(User $user, Account $account): Response
    {
        return $this->operate($user, $account);
    }

    public function freeze(User $user, Account $account): bool
    {
        return $user->isAdmin();
    }

    public function unfreeze(User $user, Account $account): bool
    {
        return $user->isAdmin();
    }

    private function operate(User $user, Account $account): Response
    {
        return $user->role === UserRole::Customer && $account->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
