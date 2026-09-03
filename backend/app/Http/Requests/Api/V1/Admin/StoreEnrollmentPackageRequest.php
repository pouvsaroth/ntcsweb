<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Uniqueness/room-membership is checked in withValidator() below
            // — mirrors StoreEnrollmentRequest's own table_id handling.
            'table_id' => ['nullable', Rule::exists('classroom_tables', 'id')->where('tenant_id', $tenantId)],

            'enrolled_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('class_id') || $validator->errors()->has('table_id')) {
                return;
            }

            $class = DB::table('classes')->where('id', $this->input('class_id'))->first();
            if ($class === null || $class->classroom_id === null) {
                return;
            }

            $hasTables = DB::table('classroom_tables')->where('classroom_id', $class->classroom_id)->exists();
            if (! $hasTables) {
                return;
            }

            $tableId = $this->input('table_id');

            if ($tableId === null) {
                $validator->errors()->add('table_id', __('Pick a table for this class.'));

                return;
            }

            $belongsToRoom = DB::table('classroom_tables')->where('id', $tableId)->where('classroom_id', $class->classroom_id)->exists();
            if (! $belongsToRoom) {
                $validator->errors()->add('table_id', __("This table does not belong to the selected class's room."));

                return;
            }

            $taken = DB::table('enrollments')
                ->where('class_id', $this->input('class_id'))
                ->where('table_id', $tableId)
                ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ->exists();

            if ($taken) {
                $validator->errors()->add('table_id', __('This table is already taken in this class.'));
            }
        });
    }
}
