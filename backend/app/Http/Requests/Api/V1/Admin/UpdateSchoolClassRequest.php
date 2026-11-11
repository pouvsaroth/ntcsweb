<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Enrollment;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Services\Billing\CompanyInvoiceService;
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

        // Teachers: a position carrying the Teacher role. Assistants: a
        // position carrying the Teacher Assistant role. Resigned staff can't
        // be newly added. Anyone already on this class in that slot still
        // passes, so saving an older class doesn't fail.
        $resignedIds = Staff::resignedIds();
        $teacherPositionIds = Position::teacherPositionIds();
        $assistantPositionIds = Position::teacherAssistantPositionIds();
        $currentTeacherIds = $class instanceof SchoolClass ? $class->teachers()->pluck('staff.id')->all() : [];
        $currentAssistantIds = $class instanceof SchoolClass ? $class->assistantTeachers()->pluck('staff.id')->all() : [];
        $isTeacherStaff = fn ($query) => $query->where(fn ($q) => $q
            ->where(fn ($n) => $n->whereIn('position_id', $teacherPositionIds)->whereNotIn('id', $resignedIds))
            ->orWhereIn('id', $currentTeacherIds));
        $isAssistantStaff = fn ($query) => $query->where(fn ($q) => $q
            ->where(fn ($n) => $n->whereIn('position_id', $assistantPositionIds)->whereNotIn('id', $resignedIds))
            ->orWhereIn('id', $currentAssistantIds));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('tenant.classes', 'code')->ignore($class)],
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
        // Billing > Companies: a class holds either one company's students
        // (no invoices of their own) or students paying for themselves —
        // never both. So who pays can only change while nobody is in it.
        $validator->after(function (Validator $validator) {
            /** @var SchoolClass $class */
            $class = $this->route('class');
            if (! $this->has('company_id') || $validator->errors()->has('company_id')) {
                return;
            }
            $new = $this->input('company_id') === null ? null : (int) $this->input('company_id');
            if ($new === ($class->company_id === null ? null : (int) $class->company_id)) {
                return;
            }

            $invoice = app(CompanyInvoiceService::class)->openInvoiceFor($class);
            if ($invoice !== null) {
                $validator->errors()->add('company_id', __('This class is already invoiced to its company (:number). Cancel or void that invoice before changing who pays.', ['number' => $invoice->invoice_number]));

                return;
            }

            if ($class->enrollments()->whereNotIn('status', Enrollment::STATUSES_FINISHED)->exists()) {
                $validator->errors()->add('company_id', __('Students are already in this class. Who pays can only change while the class has no students.'));
            }
        });

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
