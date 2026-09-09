<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\FinancialTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FinancialTransaction::class) ?? false;
    }

    public function rules(): array
    {
        $bankOrCash = Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true);

        return [
            'from_account_id' => ['required', 'different:to_account_id', $bankOrCash],
            'to_account_id' => ['required', $bankOrCash],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
