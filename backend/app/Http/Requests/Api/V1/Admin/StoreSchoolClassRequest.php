<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Position;
use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a class together with its weekly schedule (the "study day" /
 * "study time" pairs) in one request — a class with no meeting time isn't a
 * usable class, so splitting this into two separate calls would just move
 * the transaction boundary into the client.
 */
class StoreSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SchoolClass::class) ?? false;
    }

    public function rules(): array
    {
        // Teachers: a position carrying the Teacher role. Assistants: a
        // position carrying the Teacher Assistant role.
        $isTeacherStaff = fn ($query) => $query->whereIn('position_id', Position::teacherPositionIds());
        $isAssistantStaff = fn ($query) => $query->whereIn('position_id', Position::teacherAssistantPositionIds());

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.classes', 'code')],
            'teacher_ids' => ['sometimes', 'array'],
            'teacher_ids.*' => [Rule::exists('tenant.staff', 'id')->where($isTeacherStaff)],
            'assistant_teacher_ids' => ['sometimes', 'array'],
            'assistant_teacher_ids.*' => [Rule::exists('tenant.staff', 'id')->where($isAssistantStaff)],
            'classroom_id' => ['nullable', Rule::exists('tenant.classrooms', 'id')],
            'academic_program_id' => ['nullable', Rule::exists('tenant.academic_programs', 'id')],
            'grade_level_id' => ['nullable', Rule::exists('tenant.grade_levels', 'id')],
            'academic_year_id' => ['nullable', Rule::exists('tenant.academic_years', 'id')->whereNull('deleted_at')],
            // Billing > Companies: makes this a company class (no per-student invoices).
            'company_id' => ['nullable', Rule::exists('tenant.companies', 'id')],
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $overlap = array_intersect($this->input('teacher_ids', []), $this->input('assistant_teacher_ids', []));

            if ($overlap !== []) {
                $validator->errors()->add('assistant_teacher_ids', 'A staff member cannot be both a teacher and an assistant teacher on the same class.');
            }
        });
    }
}
