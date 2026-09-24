<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\BankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);
        $query = Account::with('currency');
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return AccountResource::collection($query->orderByDesc('id')->paginate(25))
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreAccountRequest $request, BankingService $banking): JsonResponse
    {
        $account = $banking->openAccount($request->user(), $request->validated('currency'));

        return (new AccountResource($account))->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function show(Account $account): JsonResponse
    {
        Gate::authorize('view', $account);

        return (new AccountResource($account->load('currency')))->response()->header('Cache-Control', 'no-store');
    }
}
