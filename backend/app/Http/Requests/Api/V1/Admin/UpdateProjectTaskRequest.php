<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTask;
use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectTask|null $task */
        $task = $this->route('project_task');

        return $task !== null && ($this->user()?->can('update', $task->project) ?? false);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        /** @var ProjectTask $task */
        $task = $this->route('project_task');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in([ProjectTask::PRIORITY_LOW, ProjectTask::PRIORITY_MEDIUM, ProjectTask::PRIORITY_HIGH])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'project_milestone_id' => ['nullable', 'integer', Rule::exists('tenant.project_milestones', 'id')->where('project_id', $task->project_id)],
            // Only active staff (with a login) can be assigned — see
            // ProjectController::assignees(). Re-saving a card whose assignee
            // has since left keeps them, instead of blocking every edit.
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                ...((int) $this->input('assignee_id') === $task->assignee_id
                    ? []
                    : [Rule::exists('tenant.staff', 'user_id')->where('status', Staff::STATUS_ACTIVE)]),
            ],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['integer', Rule::exists('tenant.project_labels', 'id')],
        ];
    }

    /**
     * A `sometimes` update of just one of start_date/due_date must still be
     * validated against the other — falling back to the task's current
     * value for whichever of the two wasn't sent — so this can't be
     * expressed as a static `after_or_equal:` rule string in rules().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ProjectTask $task */
            $task = $this->route('project_task');

            $startDate = $this->input('start_date', $task->start_date?->toDateString());
            $dueDate = $this->input('due_date', $task->due_date?->toDateString());

            if ($startDate && $dueDate && $dueDate < $startDate) {
                $validator->errors()->add('due_date', 'The due date must be on or after the start date.');
            }
        });
    }
}
