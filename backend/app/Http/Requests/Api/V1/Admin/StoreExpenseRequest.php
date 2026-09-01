<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Expense;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Billing\PaymentMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Deliberately no `status` field beyond DRAFT/PENDING_APPROVAL — an expense cannot be created already APPROVED/PAID/REJECTED, those only happen through ExpenseService's own transitions. */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Expense::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'expense_date' => ['nullable', 'date'],
            'account_id' => [
                'required',
                Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)->where('type', AccountType::EXPENSE)->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
            'vendor' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([ExpenseStatus::DRAFT, ExpenseStatus::PENDING_APPROVAL])],
        ];
    }
}
