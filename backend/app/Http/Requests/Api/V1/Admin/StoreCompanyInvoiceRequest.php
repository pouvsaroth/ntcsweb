<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Billing > Invoices > Invoice by class. `amount` is the class total staff
 * agreed with the company — typed in, not worked out per student. See
 * CompanyInvoiceService.
 */
class StoreCompanyInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', Invoice::class)) {
            return false;
        }

        // Taking money now also needs the right to record payments.
        return (float) $this->input('received_amount', 0) <= 0 || $user->hasPermission(Permissions::PAYMENTS_CREATE);
    }

    public function rules(): array
    {
        return [
            'class_id' => ['required', Rule::exists('tenant.classes', 'id')],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'currency' => ['required', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'received_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((float) $this->input('discount', 0) > (float) $this->input('amount', 0)) {
                $validator->errors()->add('discount', __('The discount cannot exceed the fee.'));
            }

            if ((float) $this->input('received_amount', 0) > 0 && $this->input('payment_method') === null) {
                $validator->errors()->add('payment_method', __('A payment method is required when recording a payment.'));
            }
        });
    }
}
