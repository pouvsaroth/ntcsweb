<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ClassroomTable;
use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'classroom_id' => ['required', Rule::exists('classrooms', 'id')->where('tenant_id', $tenantId)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('classroom_tables')->where('tenant_id', $tenantId)->where('classroom_id', $this->input('classroom_id')),
            ],
        ];
    }
}
