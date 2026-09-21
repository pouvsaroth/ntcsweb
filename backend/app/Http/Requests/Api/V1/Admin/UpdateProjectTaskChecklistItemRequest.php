<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTaskChecklistItem;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectTaskChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectTaskChecklistItem|null $item */
        $item = $this->route('project_task_checklist_item');

        return $item !== null && ($this->user()?->can('update', $item->task->project) ?? false);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'is_completed' => ['sometimes', 'boolean'],
        ];
    }
}
