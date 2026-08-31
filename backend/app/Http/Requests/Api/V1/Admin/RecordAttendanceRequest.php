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
                Rule::exists('enrollments', 'id')->where('tenant_id', $tenantId)->where('class_id', $class->getKey()),
            ],
            'entries.*.status' => ['required', Rule::in(AttendanceStatus::all())],
            'entries.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
