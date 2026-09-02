<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ProgramOffering;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProgramOffering $programOffering */
        $programOffering = $this->route('program_offering');

        return $this->user()?->can('update', $programOffering) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var ProgramOffering $programOffering */
        $programOffering = $this->route('program_offering');
        $programId = $this->input('academic_program_id', $programOffering->academic_program_id);
        $modeId = $this->input('study_mode_id', $programOffering->study_mode_id);

        return [
            'academic_program_id' => ['sometimes', 'required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'study_mode_id' => ['sometimes', 'required', Rule::exists('study_modes', 'id')->where('tenant_id', $tenantId)],
            'academic_year_id' => [
                'nullable', Rule::exists('academic_years', 'id')->where('tenant_id', $tenantId),
                Rule::unique('program_offerings')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('academic_program_id', $programId)
                    ->where('study_mode_id', $modeId))->ignore($programOffering),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([ProgramOffering::STATUS_ACTIVE, ProgramOffering::STATUS_CLOSED])],
        ];
    }
}
