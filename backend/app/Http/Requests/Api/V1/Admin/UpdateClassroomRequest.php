<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Classroom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Classroom $classroom */
        $classroom = $this->route('classroom');

        return $this->user()?->can('update', $classroom) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $classroom = $this->route('classroom');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('classrooms')->where('tenant_id', $tenantId)->ignore($classroom),
            ],
            'code' => ['nullable', 'string', 'max:32'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'location' => ['nullable', 'string', 'max:255'],
            'building_id' => ['nullable', Rule::exists('buildings', 'id')->where('tenant_id', $tenantId)],
            'status' => ['sometimes', Rule::in([Classroom::STATUS_ACTIVE, Classroom::STATUS_INACTIVE])],
        ];
    }
}
