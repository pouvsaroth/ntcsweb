<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Position;
use App\Models\SchoolClass;
use App\Support\Tenancy\TenantContext;
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
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('classes')->where('tenant_id', $tenantId)],
            // Must be a Staff member holding the "Teacher" position — see
            // TeacherPositionSeeder. `staff` lives in the tenant database and
            // `positions` in the central one, so the "Teacher" position id(s)
            // are resolved from the central connection first rather than via
            // a subquery that can't cross databases.
            'teacher_id' => ['nullable', Rule::exists('tenant.staff', 'id')->where(
                fn ($query) => $query->whereIn('position_id', Position::query()->where('tenant_id', $tenantId)->where('name', 'Teacher')->pluck('id'))
            )],
            'classroom_id' => ['nullable', Rule::exists('classrooms', 'id')->where('tenant_id', $tenantId)],
            'academic_program_id' => ['nullable', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
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
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],

            // The menu of registration packages this class session offers —
            // mirrors book_ids exactly, see class_course_package's migration.
            'course_package_ids' => ['sometimes', 'array'],
            'course_package_ids.*' => [Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
