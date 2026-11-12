<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use App\Models\Tenant;
use App\Services\Accounting\ExpenseService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Deliberately no `status` field beyond DRAFT/PENDING_APPROVAL — an expense cannot be created already APPROVED/PAID/REJECTED, those only happen through ExpenseService's own transitions. */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', Expense::class)) {
            return false;
        }

        return ! $this->boolean('paid_now') || ExpenseService::maySelfApprove($user);
    }

    public function rules(): array
    {
        return [
            'expense_date' => ['nullable', 'date'],
            // The month it is for (paid today for September): 'YYYY-MM'.
            'for_month' => ['nullable', 'date_format:Y-m'],
            'currency' => ['nullable', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'account_id' => [
                'required',
                Rule::exists('tenant.accounts', 'id')->where('type', AccountType::EXPENSE)->where('is_active', true),
            ],
            // Who it is for (Newtech, Flexi, Family...): required on every new expense.
            'expense_entity_id' => ['required', 'integer', Rule::exists('tenant.expense_entities', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
            'supplier_id' => ['nullable', 'integer', Rule::exists('tenant.suppliers', 'id')],
            'vendor' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // "Paid now": saved approved and paid from this cash/bank account (needs Approve + Pay).
            'paid_now' => ['sometimes', 'boolean'],
            'cash_account_id' => ['required_if_accepted:paid_now', 'nullable', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'paid_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([ExpenseStatus::DRAFT, ExpenseStatus::PENDING_APPROVAL])],
        ];
    }
}
