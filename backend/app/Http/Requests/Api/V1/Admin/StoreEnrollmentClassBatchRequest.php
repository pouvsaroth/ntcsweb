<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enrollments > Add students to class. No fee, discount or payment fields:
 * the class is paid by its company, invoiced as one total — see
 * EnrollmentService::enrollClassBatch(), which also checks each student's
 * table and duplicate enrollment and reports them per row.
 */
class StoreEnrollmentClassBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'class_id' => ['required', Rule::exists('tenant.classes', 'id')],
            'course_package_id' => ['required', Rule::exists('tenant.course_packages', 'id')],
            'enrolled_at' => ['nullable', 'date'],
            'students' => ['required', 'array', 'min:1', 'max:200'],
            'students.*.student_id' => ['required', 'integer', 'distinct', Rule::exists('tenant.students', 'id')],
            'students.*.table_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'students.*.student_id.distinct' => __('This student is in the list twice.'),
        ];
    }
}
