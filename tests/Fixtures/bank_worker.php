<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\User;
use App\Services\BankingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || $app->configurationIsCached()
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'mvp_bank_test'
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('The banking worker may only use the dedicated MySQL test database.');
}

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');

try {
    $service = $app->make(BankingService::class);
    $user = User::findOrFail($payload['user_id']);
    if ($payload['operation'] === 'open') {
        $record = $service->openAccount($user, 'EGP');
    } else {
        $record = $service->execute(
            $user,
            Account::findOrFail($payload['account_id']),
            TransactionType::from($payload['operation']),
            $payload['amount'],
            $payload['key'],
            $payload['destination'] ?? null,
        );
    }
    echo json_encode(['status' => $record->wasRecentlyCreated ? 201 : 200, 'reference' => $record->reference], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
