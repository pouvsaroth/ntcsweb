<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Classroom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Classroom::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tenant.classrooms', 'name')],
            'code' => ['nullable', 'string', 'max:32'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'location' => ['nullable', 'string', 'max:255'],
            'building_id' => ['nullable', Rule::exists('tenant.buildings', 'id')],
            'status' => ['sometimes', Rule::in([Classroom::STATUS_ACTIVE, Classroom::STATUS_INACTIVE])],
        ];
    }
}
