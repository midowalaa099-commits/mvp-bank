<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Transaction::class);
        $query = Transaction::query();
        if (! $request->user()->isAdmin()) {
            $query->where(function (Builder $query) use ($request): void {
                $query->whereHas('sourceAccount', fn (Builder $accounts) => $accounts->where('user_id', $request->user()->id))
                    ->orWhereHas('destinationAccount', fn (Builder $accounts) => $accounts->where('user_id', $request->user()->id));
            });
        }

        return $this->history($query);
    }

    public function forAccount(Account $account): JsonResponse
    {
        Gate::authorize('view', $account);

        return $this->history(Transaction::query()->where(function (Builder $query) use ($account): void {
            $query->where('source_account_id', $account->id)->orWhere('destination_account_id', $account->id);
        }));
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load(['currency', 'sourceAccount', 'destinationAccount']);
        Gate::authorize('view', $transaction);

        return (new TransactionResource($transaction))->response()->header('Cache-Control', 'no-store');
    }

    /** @param Builder<Transaction> $query */
    private function history(Builder $query): JsonResponse
    {
        return TransactionResource::collection($query->with(['currency', 'sourceAccount', 'destinationAccount'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25))
            ->response()->header('Cache-Control', 'no-store');
    }
}
