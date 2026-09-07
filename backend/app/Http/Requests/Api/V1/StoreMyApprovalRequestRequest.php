<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'form_template_id' => [
                'required', 'integer',
                Rule::exists('form_templates', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'subject' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
