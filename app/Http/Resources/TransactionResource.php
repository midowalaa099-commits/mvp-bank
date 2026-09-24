<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'type' => $this->type->value,
            'currency' => $this->currency->code,
            'amount' => Money::format($this->amount_minor, $this->currency->decimal_places),
            'source_account' => $this->sourceAccount?->reference,
            'destination_account' => $this->destinationAccount?->reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
