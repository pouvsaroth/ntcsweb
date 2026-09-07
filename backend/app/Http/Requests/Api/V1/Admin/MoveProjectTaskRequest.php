<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProjectTask;
use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'project_column_id' => ['required', 'integer', Rule::exists('project_columns', 'id')->where('tenant_id', $tenantId)],
            'order' => ['required', 'integer', 'min:0'],
        ];
    }
}
