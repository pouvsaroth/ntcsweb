<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ApprovalGroup;
use App\Models\Interview;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schedule or change an interview — the permission check is the
 * controller's authorize() against RecruitmentPolicy. Interviewers must be
 * working staff of this school with an account (same pool as approval
 * groups, see ApprovalGroup::eligibleUserIds()).
 */
class InterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $eligible = null;

        return [
            'applicant_id' => [$required, 'integer', Rule::exists('tenant.applicants', 'id')],
            'round' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'scheduled_at' => [$required, 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'mode' => [$required, Rule::in(Interview::MODES)],
            'location' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(Interview::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'interviewer_ids' => [$required, 'array', 'min:1'],
            'interviewer_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where('tenant_id', app(TenantContext::class)->id()),
                function (string $attribute, mixed $value, Closure $fail) use (&$eligible) {
                    $eligible ??= ApprovalGroup::eligibleUserIds();
                    if (! $eligible->contains((int) $value)) {
                        $fail('Only working staff can interview.');
                    }
                },
            ],
        ];
    }
}
