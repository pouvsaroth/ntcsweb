<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Department $department */
        $department = $this->route('department');

        return $this->user()?->can('update', $department) ?? false;
    }

    public function rules(): array
    {
        /** @var Department $department */
        $department = $this->route('department');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('tenant.departments', 'code')->ignore($department)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
