<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ClassroomTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassroomTable::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', Rule::exists('tenant.classrooms', 'id')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('tenant.classroom_tables', 'name')->where('classroom_id', $this->input('classroom_id')),
            ],
        ];
    }
}
