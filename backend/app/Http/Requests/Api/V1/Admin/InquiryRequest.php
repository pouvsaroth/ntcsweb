<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Inquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create/update a Front Office inquiry; the permission check is the controller's authorize(). */
class InquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'inquiry_date' => [$required, 'date_format:Y-m-d'],
            'name' => [$required, 'string', 'max:150'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'phone' => [$required, 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'interested_in' => ['nullable', 'string', 'max:255'],
            'source' => [$required, Rule::in(Inquiry::SOURCES)],
            'status' => ['sometimes', Rule::in(Inquiry::STATUSES)],
            'next_follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'assigned_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
