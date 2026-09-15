<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SchoolClass;
use App\Support\Academic\AttendanceStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One save = one class's whole roster for one date. `entries.*.enrollment_id`
 * is checked against this exact class — a student enrolled elsewhere can't
 * be marked here even by id-guessing.
 */
class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SchoolClass $class */
        $class = $this->route('class');

        return $this->user()?->can('recordAttendance', $class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var SchoolClass $class */
        $class = $this->route('class');

        return [
            'date' => ['required', 'date', 'before_or_equal:today'],

            'entries' => ['required', 'array', 'min:1'],
            'entries.*.enrollment_id' => [
                'required',
                'distinct',
                Rule::exists('tenant.enrollments', 'id')->where('class_id', $class->getKey()),
            ],
            'entries.*.status' => ['required', Rule::in(AttendanceStatus::all())],
            // Only meaningful when status is LATE, but validated unconditionally rather than
            // rejected/ignored otherwise — a stray value on a non-late entry is harmless.
            'entries.*.late_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'entries.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
