<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SchoolClass;
use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();
        $class = $this->route('class');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('classes')->where('tenant_id', $tenantId)->ignore($class)],
            // Must be a Staff member holding the "Teacher" position — see
            // TeacherPositionSeeder.
            'teacher_id' => ['nullable', Rule::exists('staff', 'id')->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->whereIn('position_id', fn ($sub) => $sub->select('id')->from('positions')->where('tenant_id', $tenantId)->where('name', 'Teacher'));
            })],
            'classroom_id' => ['nullable', Rule::exists('classrooms', 'id')->where('tenant_id', $tenantId)],
            'academic_program_id' => ['nullable', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
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
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],

            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => [Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
