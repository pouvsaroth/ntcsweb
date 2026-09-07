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

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in([ProjectTask::PRIORITY_LOW, ProjectTask::PRIORITY_MEDIUM, ProjectTask::PRIORITY_HIGH])],
            'due_date' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
