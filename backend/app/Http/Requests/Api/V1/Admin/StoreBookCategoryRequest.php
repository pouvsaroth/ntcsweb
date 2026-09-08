<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\BookCategory;
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
        return [
            'academic_program_id' => ['required', Rule::exists('tenant.academic_programs', 'id')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('tenant.book_categories', 'name')->where('academic_program_id', $this->input('academic_program_id')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
