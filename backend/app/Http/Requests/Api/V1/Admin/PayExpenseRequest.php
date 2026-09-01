<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'cash_account_id' => ['required', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)->where('is_bank_or_cash', true)->where('is_active', true)],
            'paid_date' => ['nullable', 'date'],
        ];
    }
}
