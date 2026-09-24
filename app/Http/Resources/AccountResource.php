<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'currency' => $this->currency->code,
            'balance' => Money::format($this->balance_minor, $this->currency->decimal_places),
            'status' => $this->status->value,
            'user_id' => $this->when($request->user()->isAdmin(), $this->user_id),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
