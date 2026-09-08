<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicYear::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Scoped to the `tenant` connection, not a tenant_id column —
            // this table lives in the school's own database (see
            // AcademicYear's docblock), so uniqueness is already per-school
            // just by virtue of which database is being queried.
            'name' => ['required', 'string', 'max:20', Rule::unique('tenant.academic_years', 'name')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }
}
