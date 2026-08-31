<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/** Shared by both cancel and void — the only input either needs is a reason. */
class CloseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $this->user()?->can('cancel', $invoice) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
