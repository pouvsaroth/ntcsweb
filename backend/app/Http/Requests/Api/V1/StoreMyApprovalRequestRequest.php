<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identity-gated, not permission-gated — any signed-in user may submit a
 * request against any active form template. See
 * MyApprovalRequestController's docblock.
 */
class StoreMyApprovalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'form_template_id' => [
                'required', 'integer',
                // Scoped to the `tenant` connection, not a tenant_id
                // column — this table lives in the school's own database.
                Rule::exists('tenant.form_templates', 'id')->where('is_active', true),
            ],
            'subject' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
