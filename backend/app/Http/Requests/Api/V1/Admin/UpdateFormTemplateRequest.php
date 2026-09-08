<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

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
        $template = $this->route('form_template');

        return [
            // See StoreFormTemplateRequest for why this targets the
            // `tenant` connection instead of a tenant_id column.
            'form_category_id' => ['sometimes', 'required', 'integer', Rule::exists('tenant.form_categories', 'id')],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('tenant.form_templates', 'code')->ignore($template)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
