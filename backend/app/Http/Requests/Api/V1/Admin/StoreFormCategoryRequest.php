<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\FormCategory;
use Illuminate\Foundation\Http\FormRequest;

class StoreFormCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FormCategory::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
