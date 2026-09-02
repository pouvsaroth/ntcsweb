<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AcademicYear $academicYear */
        $academicYear = $this->route('academic_year');

        return $this->user()?->can('update', $academicYear) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var AcademicYear $academicYear */
        $academicYear = $this->route('academic_year');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('academic_years')->where('tenant_id', $tenantId)->ignore($academicYear)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }
}
