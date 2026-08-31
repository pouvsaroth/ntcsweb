<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Program::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'level' => ['sometimes', Rule::in([Program::LEVEL_BEGINNER, Program::LEVEL_INTERMEDIATE, Program::LEVEL_ADVANCED])],
            'duration_label' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([Program::STATUS_ACTIVE, Program::STATUS_INACTIVE])],
        ];
    }
}
