<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\LeavePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Create/update a leave policy (HRM > Leave Management > Leave policies).
 * Day counts go in half days. Only one active policy per leave type and job
 * grade (or per type for everyone), so which one applies is never a guess.
 */
class LeavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $halfDays = ['numeric', 'min:0', 'max:366', 'multiple_of:0.5'];

        return [
            'leave_type_id' => [$required, 'integer', 'exists:tenant.leave_types,id'],
            'job_grade_id' => ['nullable', 'integer', 'exists:tenant.job_grades,id'],
            'name' => [$required, 'string', 'max:255'],
            'days_per_year' => [$required, ...$halfDays],
            'min_service_months' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'prorate_first_year' => ['sometimes', 'boolean'],
            'service_bonus_every_years' => ['nullable', 'integer', 'min:1', 'max:50'],
            'service_bonus_days' => ['sometimes', ...$halfDays],
            'max_days_per_year' => ['nullable', ...$halfDays],
            'max_carry_forward_days' => ['sometimes', ...$halfDays],
            'carry_forward_expiry_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'min_notice_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var LeavePolicy|null $current */
                $current = $this->route('leave_policy');
                $typeId = $this->input('leave_type_id', $current?->leave_type_id);
                $gradeId = $this->has('job_grade_id') ? $this->input('job_grade_id') : $current?->job_grade_id;

                if (! $this->boolean('is_active', $current?->is_active ?? true)) {
                    return;
                }

                $clash = LeavePolicy::query()
                    ->where('leave_type_id', $typeId)
                    ->where('is_active', true)
                    ->when($gradeId, fn ($q) => $q->where('job_grade_id', $gradeId), fn ($q) => $q->whereNull('job_grade_id'))
                    ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                    ->exists();

                if ($clash) {
                    $validator->errors()->add('job_grade_id', 'This leave type already has an active policy for this job grade (or for everyone). Edit that one, or deactivate it first.');
                }
            },
        ];
    }
}
