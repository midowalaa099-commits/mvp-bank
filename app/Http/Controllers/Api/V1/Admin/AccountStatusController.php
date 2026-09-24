<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeAccountStatusRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\AccountStatusChangeResource;
use App\Models\Account;
use App\Services\AccountStatusService;
use Illuminate\Http\JsonResponse;

class AccountStatusController extends Controller
{
    public function __construct(private AccountStatusService $statuses) {}

    public function freeze(ChangeAccountStatusRequest $request, Account $account): JsonResponse
    {
        return $this->change($request, $account, AccountStatus::Frozen);
    }

    public function unfreeze(ChangeAccountStatusRequest $request, Account $account): JsonResponse
    {
        return $this->change($request, $account, AccountStatus::Active);
    }

    public function history(Account $account): JsonResponse
    {
        return AccountStatusChangeResource::collection($account->statusChanges()->with('performer')->orderByDesc('id')->paginate(25))
            ->response()->header('Cache-Control', 'no-store');
    }

    private function change(ChangeAccountStatusRequest $request, Account $account, AccountStatus $status): JsonResponse
    {
        $updated = $this->statuses->change($request->user(), $account, $status, $request->validated('reason'));

        return (new AccountResource($updated))->response()->header('Cache-Control', 'no-store');
    }
}
