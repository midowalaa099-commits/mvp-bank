<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoneyOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', 'string', 'max:64'],
            'idempotency_key' => ['bail', 'required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'destination_account' => [Rule::when($this->routeIs('accounts.transfer'), ['required', 'uuid'], ['prohibited'])],
            'currency' => ['prohibited'],
        ];
    }
}
