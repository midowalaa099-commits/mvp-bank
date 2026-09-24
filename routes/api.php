<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\AccountStatusController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MoneyOperationController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
})->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function (): void {
    Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'show']);
    Route::post('/accounts/{account}/deposits', [MoneyOperationController::class, 'deposit'])->middleware('can:deposit,account')->name('accounts.deposit');
    Route::post('/accounts/{account}/withdrawals', [MoneyOperationController::class, 'withdraw'])->middleware('can:withdraw,account')->name('accounts.withdraw');
    Route::post('/accounts/{account}/transfers', [MoneyOperationController::class, 'transfer'])->middleware('can:transfer,account')->name('accounts.transfer');
    Route::get('/accounts/{account}/transactions', [TransactionController::class, 'forAccount'])->name('accounts.transactions');
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);

    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function (): void {
        Route::post('/accounts/{account}/freeze', [AccountStatusController::class, 'freeze'])->name('accounts.freeze');
        Route::post('/accounts/{account}/unfreeze', [AccountStatusController::class, 'unfreeze'])->name('accounts.unfreeze');
        Route::get('/accounts/{account}/status-changes', [AccountStatusController::class, 'history'])->name('accounts.status-changes');
    });
});
