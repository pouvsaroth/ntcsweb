<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `student_id`/`class_id`/`course_package_id` are deliberately not
 * editable — moving an enrollment to a different student, class, or course
 * is not an "update," it's a new enrollment. Delete and re-create instead.
 */
class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Enrollment $enrollment */
        $enrollment = $this->route('enrollment');

        return $this->user()?->can('update', $enrollment) ?? false;
    }

    public function rules(): array
    {
        return [
            'enrolled_at' => ['sometimes', 'required', 'date'],
            // Not before the enrollment date (the one sent, or the saved one).
            'start_study_on' => ['sometimes', 'required', 'date', 'after_or_equal:'.($this->input('enrolled_at') ?: $this->route('enrollment')?->enrolled_at?->toDateString() ?: '1900-01-01')],
            'status' => ['sometimes', Rule::in([
                Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_DROPPED,
            ])],
        ];
    }
}
