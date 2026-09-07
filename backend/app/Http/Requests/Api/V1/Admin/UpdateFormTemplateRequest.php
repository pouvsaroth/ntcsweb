<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('form_template');

        return $this->user()?->can('update', $template) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $template = $this->route('form_template');

        return [
            'form_category_id' => ['sometimes', 'required', 'integer', Rule::exists('form_categories', 'id')->where('tenant_id', $tenantId)],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('form_templates')->where('tenant_id', $tenantId)->ignore($template)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
