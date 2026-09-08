<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveProjectTaskRequest extends FormRequest
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
            // Scoped to the `tenant` connection, not a tenant_id column —
            // project_columns lives in the school's own database.
            'project_column_id' => ['required', 'integer', Rule::exists('tenant.project_columns', 'id')],
            'order' => ['required', 'integer', 'min:0'],
        ];
    }
}
