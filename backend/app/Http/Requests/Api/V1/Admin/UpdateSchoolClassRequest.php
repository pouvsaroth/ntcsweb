<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SchoolClass $class */
        $class = $this->route('class');

        return $this->user()?->can('update', $class) ?? false;
    }

    public function rules(): array
    {
        $class = $this->route('class');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.classes', 'code')->ignore($class)],
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

            // When present, replaces the class's entire weekly schedule —
            // see SchoolClassController::syncSchedules().
            'schedules' => ['sometimes', 'array'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i', 'after:schedules.*.start_time'],

            'book_ids' => ['sometimes', 'array'],
            'book_ids.*' => [Rule::exists('tenant.books', 'id')],

            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => [Rule::exists('tenant.course_packages', 'id')],
        ];
    }
}
