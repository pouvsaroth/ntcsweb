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
use Illuminate\Validation\Validator;

/** Only a PENDING_APPROVAL (i.e. not yet approved) expense may be edited — see ExpenseController::update(). */
class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Expense $expense */
        $expense = $this->route('expense');

        return $this->user()?->can('update', $expense) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'expense_date' => ['sometimes', 'required', 'date'],
            'account_id' => [
                'sometimes', 'required',
                Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)->where('type', AccountType::EXPENSE)->where('is_active', true),
            ],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
            'vendor' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Expense $expense */
            $expense = $this->route('expense');

            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL && $expense->status !== ExpenseStatus::DRAFT) {
                $validator->errors()->add('status', 'Only a draft or pending expense can be edited.');
            }
        });
    }
}
