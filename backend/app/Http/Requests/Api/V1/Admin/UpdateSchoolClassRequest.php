<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Position;
use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\Validator;
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

        // Must be a Staff member holding a position that carries the
        // Teacher role — see Position::teacherPositionIds(). Shared between
        // teacher_ids and assistant_teacher_ids: eligibility is the same,
        // only the assigned role differs.
        $isTeacherStaff = fn ($query) => $query->whereIn('position_id', Position::teacherPositionIds());

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.classes', 'code')->ignore($class)],
            'teacher_ids' => ['sometimes', 'array'],
            'teacher_ids.*' => [Rule::exists('tenant.staff', 'id')->where($isTeacherStaff)],
            'assistant_teacher_ids' => ['sometimes', 'array'],
            'assistant_teacher_ids.*' => [Rule::exists('tenant.staff', 'id')->where($isTeacherStaff)],
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('teacher_ids') && ! $this->has('assistant_teacher_ids')) {
                return;
            }

            $overlap = array_intersect($this->input('teacher_ids', []), $this->input('assistant_teacher_ids', []));

            if ($overlap !== []) {
                $validator->errors()->add('assistant_teacher_ids', 'A staff member cannot be both a teacher and an assistant teacher on the same class.');
            }
        });
    }
}
