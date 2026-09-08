<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\StudyMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudyModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StudyMode $studyMode */
        $studyMode = $this->route('study_mode');

        return $this->user()?->can('update', $studyMode) ?? false;
    }

    public function rules(): array
    {
        /** @var StudyMode $studyMode */
        $studyMode = $this->route('study_mode');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('tenant.study_modes', 'code')->ignore($studyMode)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
