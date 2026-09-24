<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BankingService
{
    public function openAccount(User $user, string $currencyCode): Account
    {
        return DB::transaction(function () use ($user, $currencyCode): Account {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('create', Account::class);
            $currency = Currency::query()->where('code', $currencyCode)->firstOrFail();

            abort_if($actor->accounts()->where('currency_id', $currency->id)->exists(), 409, 'You already have an account in this currency.');

            $account = new Account;
            $account->user()->associate($actor);
            $account->currency()->associate($currency);
            $account->save();

            return $account;
        }, 3);
    }

    public function execute(User $user, Account $account, TransactionType $type, string $amount, string $key, ?string $destinationReference = null): Transaction
    {
        return DB::transaction(function () use ($user, $account, $type, $amount, $key, $destinationReference): Transaction {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            $primary = Account::with('currency')->findOrFail($account->id);
            $ability = $type === TransactionType::Withdrawal ? 'withdraw' : $type->value;
            Gate::forUser($actor)->authorize($ability, $primary);

            try {
                $minor = Money::toMinor($amount, $primary->currency->decimal_places);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
            }

            $hash = hash('sha256', json_encode([
                $type->value, $primary->reference, $primary->currency_id, $minor,
                $destinationReference === null ? null : strtolower($destinationReference),
            ], JSON_THROW_ON_ERROR));
            $previous = Transaction::query()->where('initiated_by', $actor->id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();

            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409, 'This idempotency key was already used for a different operation.');

                return $previous->load(['currency', 'sourceAccount', 'destinationAccount']);
            }

            $recipient = null;
            if ($type === TransactionType::Transfer) {
                $recipient = Account::query()->where('reference', $destinationReference)->first();
                if (! $recipient || $recipient->id === $primary->id) {
                    throw ValidationException::withMessages(['destination_account' => 'Choose a different existing destination account.']);
                }
            }

            $ids = array_filter([$primary->id, $recipient?->id]);
            sort($ids, SORT_NUMERIC);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = Account::query()->lockForUpdate()->findOrFail($id);
            }
            $primary = $locked[$primary->id];
            $recipient = $recipient ? $locked[$recipient->id] : null;

            foreach ($locked as $lockedAccount) {
                abort_if($lockedAccount->isFrozen(), 409, 'A frozen account cannot send or receive money.');
            }

            if ($recipient && $recipient->currency_id !== $primary->currency_id) {
                throw ValidationException::withMessages(['destination_account' => 'Both accounts must use the same currency.']);
            }

            $source = $type === TransactionType::Deposit ? null : $primary;
            $destination = $type === TransactionType::Deposit ? $primary : $recipient;

            abort_if($source && $source->balance_minor < $minor, 409, 'Insufficient balance.');
            abort_if($destination && $minor > PHP_INT_MAX - $destination->balance_minor, 409, 'The destination balance would exceed the supported limit.');

            if ($source) {
                $source->balance_minor -= $minor;
                $source->save();
            }
            if ($destination) {
                $destination->balance_minor += $minor;
                $destination->save();
            }

            $transaction = new Transaction;
            $transaction->initiated_by = $actor->id;
            $transaction->currency_id = $primary->currency_id;
            $transaction->type = $type;
            $transaction->amount_minor = $minor;
            $transaction->source_account_id = $source?->id;
            $transaction->destination_account_id = $destination?->id;
            $transaction->idempotency_key = $key;
            $transaction->request_hash = $hash;
            $transaction->save();

            return $transaction->load(['currency', 'sourceAccount', 'destinationAccount']);
        }, 3);
    }
}
