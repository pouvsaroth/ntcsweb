<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\FormTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FormTemplate::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Scoped to the `tenant` connection, not a tenant_id column —
            // these tables live in the school's own database.
            'form_category_id' => ['required', 'integer', Rule::exists('tenant.form_categories', 'id')],
            'code' => ['required', 'string', 'max:100', Rule::unique('tenant.form_templates', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
