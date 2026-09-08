<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ClassroomTable;
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
        $classroomTable = $this->route('classroom_table');
        $classroomId = $this->input('classroom_id', $classroomTable->classroom_id);

        return [
            'classroom_id' => ['sometimes', 'required', Rule::exists('tenant.classrooms', 'id')],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('tenant.classroom_tables', 'name')->where('classroom_id', $classroomId)->ignore($classroomTable),
            ],
        ];
    }
}
