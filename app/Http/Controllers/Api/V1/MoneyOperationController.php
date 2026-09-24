<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\MoneyOperationRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Services\BankingService;
use Illuminate\Http\JsonResponse;

class MoneyOperationController extends Controller
{
    public function __construct(private BankingService $banking) {}

    public function deposit(MoneyOperationRequest $request, Account $account): JsonResponse
    {
        return $this->execute($request, $account, TransactionType::Deposit);
    }

    public function withdraw(MoneyOperationRequest $request, Account $account): JsonResponse
    {
        return $this->execute($request, $account, TransactionType::Withdrawal);
    }

    public function transfer(MoneyOperationRequest $request, Account $account): JsonResponse
    {
        return $this->execute($request, $account, TransactionType::Transfer);
    }

    private function execute(MoneyOperationRequest $request, Account $account, TransactionType $type): JsonResponse
    {
        $transaction = $this->banking->execute(
            $request->user(), $account, $type,
            $request->validated('amount'),
            $request->validated('idempotency_key'),
            $request->validated('destination_account'),
        );

        return (new TransactionResource($transaction))->response()
            ->setStatusCode($transaction->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
