<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTask;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectTask|null $task */
        $task = $this->route('project_task');

        return $task !== null && ($this->user()?->can('update', $task->project) ?? false);
    }

    public function rules(): array
    {
        return [
            'depends_on_project_task_id' => ['required', 'integer', Rule::exists('tenant.project_tasks', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * Self-dependency and cross-project checks need the parent task loaded,
     * and the duplicate check needs a query — neither fits a stateless rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $dependsOnId = $this->input('depends_on_project_task_id');
            if (! is_numeric($dependsOnId)) {
                return;
            }

            /** @var ProjectTask $task */
            $task = $this->route('project_task');
            $dependsOnId = (int) $dependsOnId;

            if ($dependsOnId === $task->id) {
                $validator->errors()->add('depends_on_project_task_id', 'A card cannot depend on itself.');

                return;
            }

            $dependsOn = ProjectTask::query()->find($dependsOnId);

            if ($dependsOn !== null && $dependsOn->project_id !== $task->project_id) {
                $validator->errors()->add('depends_on_project_task_id', 'That card belongs to a different project.');

                return;
            }

            if ($task->dependencies()->where('depends_on_project_task_id', $dependsOnId)->exists()) {
                $validator->errors()->add('depends_on_project_task_id', 'That dependency already exists.');
            }
        });
    }
}
