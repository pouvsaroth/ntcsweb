<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `student_id`/`class_id`/`book_id` are deliberately not editable — moving
 * an enrollment to a different student, class, or book is not an "update,"
 * it's a new enrollment. Delete and re-create instead.
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
            // A discount or scholarship adjusts this after the fact — the
            // book's own catalog fee is untouched either way (see the
            // migration for why this is a snapshot, not a live read).
            'fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99'],
            'status' => ['sometimes', Rule::in([
                Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_DROPPED,
            ])],
        ];
    }
}
