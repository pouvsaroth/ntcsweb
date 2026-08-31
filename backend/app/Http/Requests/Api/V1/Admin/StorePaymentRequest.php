<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Invoice;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $this->user()?->can('create', [\App\Models\Payment::class, $invoice]) ?? false;
    }

    public function rules(): array
    {
        return [
            // Upper-bounded against the invoice's own balance in
            // PaymentService, not here — that check needs a row lock inside
            // a transaction to be race-safe (see PaymentService::record()),
            // which a validation rule can't provide.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'payment_method' => ['required', Rule::in(PaymentMethod::all())],
            'payment_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
