<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Expense $expense */
        $expense = $this->route('expense');

        return $this->user()?->can('pay', $expense) ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_account_id' => ['required', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'paid_date' => ['nullable', 'date'],
        ];
    }
}
