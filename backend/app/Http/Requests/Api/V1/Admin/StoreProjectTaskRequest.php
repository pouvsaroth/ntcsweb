<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProjectColumn|null $column */
        $column = $this->route('project_column');

        return $column !== null && ($this->user()?->can('update', $column->project) ?? false);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        /** @var ProjectColumn $column */
        $column = $this->route('project_column');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in([ProjectTask::PRIORITY_LOW, ProjectTask::PRIORITY_MEDIUM, ProjectTask::PRIORITY_HIGH])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'project_milestone_id' => ['nullable', 'integer', Rule::exists('tenant.project_milestones', 'id')->where('project_id', $column->project_id)],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['integer', Rule::exists('tenant.project_labels', 'id')],
        ];
    }
}
