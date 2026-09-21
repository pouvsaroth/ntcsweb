<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectMilestone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectMilestone|null $milestone */
        $milestone = $this->route('project_milestone');

        return $milestone !== null && ($this->user()?->can('update', $milestone->project) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
