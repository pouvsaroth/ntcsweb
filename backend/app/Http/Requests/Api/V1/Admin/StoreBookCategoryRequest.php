<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\BookCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BookCategory::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'academic_program_id' => ['required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('book_categories')->where('tenant_id', $tenantId)->where('academic_program_id', $this->input('academic_program_id')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
