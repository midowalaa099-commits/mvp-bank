<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'currency' => ['bail', 'required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'user_id' => ['prohibited'],
            'balance' => ['prohibited'],
            'balance_minor' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
