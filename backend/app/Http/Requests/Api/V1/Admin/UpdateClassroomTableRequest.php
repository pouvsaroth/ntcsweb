<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ClassroomTable;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassroomTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ClassroomTable $classroomTable */
        $classroomTable = $this->route('classroom_table');

        return $this->user()?->can('update', $classroomTable) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $classroomTable = $this->route('classroom_table');
        $classroomId = $this->input('classroom_id', $classroomTable->classroom_id);

        return [
            'classroom_id' => ['sometimes', 'required', Rule::exists('classrooms', 'id')->where('tenant_id', $tenantId)],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('classroom_tables')->where('tenant_id', $tenantId)->where('classroom_id', $classroomId)->ignore($classroomTable),
            ],
        ];
    }
}
