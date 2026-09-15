<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ExamApplication;
use App\Models\Tenant;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Print" action's fee popup — reuses the `update` ability (same as
 * Sell/Receive/Pay Back before it), since this is still just a field stamp
 * on the row, plus a Payment the same permission already implies recording
 * (see ExamApplicationPolicy::update()'s docblock).
 */
class RecordExamApplicationFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamApplication $examApplication */
        $examApplication = $this->route('exam_application');

        return $this->user()?->can('update', $examApplication) ?? false;
    }

    public function rules(): array
    {
        return [
            'fee' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'currency' => ['nullable', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'payment_method' => ['required', Rule::in(PaymentMethod::all())],
            'print_date' => ['required', 'date'],
        ];
    }
}
