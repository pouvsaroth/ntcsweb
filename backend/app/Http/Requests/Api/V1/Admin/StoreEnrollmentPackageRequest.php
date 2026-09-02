<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately has NO `fee`/`total`/`price` field at all — the fee is
 * always computed server-side from the course package's current price
 * (see EnrollmentService::enrollInPackage()), never accepted from the
 * client. This is structural, not just a review-time convention.
 */
class StoreEnrollmentPackageRequest extends FormRequest
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
            'class_id' => ['required', Rule::exists('classes', 'id')->where('tenant_id', $tenantId)],

            // Mirrors StoreEnrollmentRequest's own book_id uniqueness check —
            // scoped to non-dropped rows so a student may re-enroll in the
            // same class+package after dropping (the DB's partial unique
            // index allows exactly this); without the status exclusion here
            // a re-enrollment would 422 even though the DB would accept it.
            'course_package_id' => [
                'required',
                Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId),
                Rule::unique('enrollments')->where('tenant_id', $tenantId)->where(
                    fn ($query) => $query
                        ->where('student_id', $this->input('student_id'))
                        ->where('class_id', $this->input('class_id'))
                        ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ),
            ],
            'enrolled_at' => ['nullable', 'date'],
        ];
    }
}
