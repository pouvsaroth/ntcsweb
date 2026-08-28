<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'student_id' => ['required', Rule::exists('students', 'id')->where('tenant_id', $tenantId)],
            'class_id' => [
                'required',
                Rule::exists('classes', 'id')->where('tenant_id', $tenantId),
                // A student can't be enrolled in the same class twice — this
                // mirrors the DB's own unique(student_id, class_id) so the
                // rejection is a clean 422, not a 500 from a caught
                // constraint violation.
                Rule::unique('enrollments')->where('tenant_id', $tenantId)->where(
                    fn ($query) => $query->where('student_id', $this->input('student_id'))
                ),
            ],
            'enrolled_at' => ['required', 'date'],
            'status' => ['sometimes', Rule::in([
                Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_DROPPED,
            ])],
        ];
    }
}
