<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A real file input can't be sent with a literal HTTP PUT from a browser —
 * the frontend sends `POST` with a spoofed `_method=PUT` field, which still
 * lands here via the normal apiResource `update` route. See
 * UpdateHomeSlideRequest for the same pattern.
 */
class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Program $program */
        $program = $this->route('program');

        return $this->user()?->can('update', $program) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'level' => ['sometimes', Rule::in([Program::LEVEL_BEGINNER, Program::LEVEL_INTERMEDIATE, Program::LEVEL_ADVANCED])],
            'duration_label' => ['nullable', 'string', 'max:50'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Optional: an update that only changes text fields shouldn't
            // have to re-upload the image.
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([Program::STATUS_ACTIVE, Program::STATUS_INACTIVE])],
        ];
    }
}
