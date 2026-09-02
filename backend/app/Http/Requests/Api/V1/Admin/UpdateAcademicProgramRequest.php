<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AcademicProgram;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AcademicProgram $academicProgram */
        $academicProgram = $this->route('academic_program');

        return $this->user()?->can('update', $academicProgram) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var AcademicProgram $academicProgram */
        $academicProgram = $this->route('academic_program');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('academic_programs')->where('tenant_id', $tenantId)->ignore($academicProgram)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
