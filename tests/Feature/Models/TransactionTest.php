<?php

namespace Tests\Feature\Models;

use App\Models\Transaction;
use LogicException;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    public function test_completed_transaction_rejects_an_amount_change(): void
    {
        $transaction = (new Transaction)->newFromBuilder(['id' => 1, 'amount_minor' => 1000]);
        $transaction->amount_minor = 2000;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Completed transactions cannot be modified.');

        $transaction->save();
    }

    public function test_completed_transaction_rejects_deletion(): void
    {
        $transaction = (new Transaction)->newFromBuilder(['id' => 1]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Completed transactions cannot be deleted.');

        $transaction->delete();
    }

    public function test_transaction_serialization_hides_request_deduplication_details(): void
    {
        $transaction = (new Transaction)->newFromBuilder([
            'reference' => 'public-reference',
            'idempotency_key' => 'private-request-key',
            'request_hash' => 'private-request-hash',
        ]);

        $this->assertSame(['reference' => 'public-reference'], $transaction->toArray());
    }
}
