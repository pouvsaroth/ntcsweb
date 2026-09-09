<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately has NO rule for subtotal/total/paid_amount/balance — a
 * FormRequest silently drops any field it has no rule for, so even a client
 * that sends them gets ignored, not validated. InvoiceService computes all
 * of those from the items below; see its class docblock.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', Rule::exists('tenant.students', 'id')],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('tenant.products', 'id')->where('is_active', true)],
            'items.*.product_variant_id' => ['nullable', Rule::exists('tenant.product_variants', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.reference_type' => ['nullable', 'string', 'in:App\\Models\\Enrollment'],
            'items.*.reference_id' => ['nullable', 'integer'],
        ];
    }
}
