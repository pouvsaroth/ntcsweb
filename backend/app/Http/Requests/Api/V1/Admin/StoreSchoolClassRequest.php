<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a class together with its weekly schedule (the "study day" /
 * "study time" pairs) and the books it uses, in one request — a class with
 * no meeting time isn't a usable class, so splitting this into three
 * separate calls would just move the transaction boundary into the client.
 */
class StoreSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SchoolClass::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.classes', 'code')],
            // Must be a Staff member holding the "Teacher" position — see
            // TeacherPositionSeeder. `staff` and `positions` both live in the
            // tenant database, so this is an ordinary same-connection
            // subquery.
            'teacher_id' => ['nullable', Rule::exists('tenant.staff', 'id')->where(
                fn ($query) => $query->whereIn('position_id', fn ($sub) => $sub->select('id')->from('positions')->where('name', 'Teacher'))
            )],
            'classroom_id' => ['nullable', Rule::exists('tenant.classrooms', 'id')],
            'academic_program_id' => ['nullable', Rule::exists('tenant.academic_programs', 'id')],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in([
                SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE,
                SchoolClass::STATUS_COMPLETED, SchoolClass::STATUS_CANCELLED,
            ])],

            // The weekly schedule — "study day" (1=Monday..7=Sunday) and
            // "study time" (a start/end pair) per meeting slot.
            'schedules' => ['sometimes', 'array'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i', 'after:schedules.*.start_time'],

            'book_ids' => ['sometimes', 'array'],
            'book_ids.*' => [Rule::exists('tenant.books', 'id')],

            // The menu of registration packages this class session offers —
            // mirrors book_ids exactly, see class_course_package's migration.
            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => [Rule::exists('tenant.course_packages', 'id')],
        ];
    }
}
