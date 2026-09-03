<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\BookCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var BookCategory $bookCategory */
        $bookCategory = $this->route('book_category');

        return $this->user()?->can('update', $bookCategory) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $bookCategory = $this->route('book_category');
        $academicProgramId = $this->input('academic_program_id', $bookCategory->academic_program_id);

        return [
            'academic_program_id' => ['sometimes', 'required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('book_categories')->where('tenant_id', $tenantId)->where('academic_program_id', $academicProgramId)->ignore($bookCategory),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
