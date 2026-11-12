<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use App\Models\Tenant;
use App\Support\Accounting\AccountType;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add many: several expenses on one date. The date, month, Expense for,
 * currency, payment method and vendor are given once; each line has its own
 * account, amount and description. All lines are checked before any is saved.
 */
class StoreExpenseBatchRequest extends FormRequest
{
    public const MAX_LINES = 50;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Expense::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'for_month' => ['nullable', 'date_format:Y-m'],
            'expense_entity_id' => ['required', 'integer', Rule::exists('tenant.expense_entities', 'id')->where('is_active', true)],
            'currency' => ['nullable', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
            'supplier_id' => ['nullable', 'integer', Rule::exists('tenant.suppliers', 'id')],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.account_id' => [
                'required',
                Rule::exists('tenant.accounts', 'id')->where('type', AccountType::EXPENSE)->where('is_active', true),
            ],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
