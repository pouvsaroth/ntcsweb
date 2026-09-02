<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProgramOffering;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProgramOffering::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'academic_program_id' => ['required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'study_mode_id' => ['required', Rule::exists('study_modes', 'id')->where('tenant_id', $tenantId)],
            'academic_year_id' => [
                'nullable', Rule::exists('academic_years', 'id')->where('tenant_id', $tenantId),
                Rule::unique('program_offerings')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('academic_program_id', $this->input('academic_program_id'))
                    ->where('study_mode_id', $this->input('study_mode_id'))),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([ProgramOffering::STATUS_ACTIVE, ProgramOffering::STATUS_CLOSED])],
        ];
    }
}
