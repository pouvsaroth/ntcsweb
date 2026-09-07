<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ExamApplication;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's own exam application — see ExamApplication's own docblock.
 * Unlike LeaveRequestService::approve(), approve()/reject() here have no
 * side effect beyond the row itself: exam-day logistics stay a manual,
 * offline school process in v1. The fee is never charged automatically —
 * see the migration's docblock for why this app's payment story here is
 * "show the fee, student self-declares paid, staff verifies in Billing,"
 * the same as every other payment in this app today.
 */
final class ExamApplicationService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{enrollment_id:int, exam_date:string, exam_time:string, table_no:string}  $data
     */
    public function submit(Student $student, array $data): ExamApplication
    {
        $tenant = $this->context->getOrFail();

        if ($tenant->exam_fee_amount === null) {
            throw ValidationException::withMessages([
                'exam_fee_amount' => 'The exam fee has not been configured yet. Please contact your school.',
            ]);
        }

        return ExamApplication::query()->create([
            'student_id' => $student->id,
            'enrollment_id' => $data['enrollment_id'],
            'exam_date' => $data['exam_date'],
            'exam_time' => $data['exam_time'],
            'table_no' => $data['table_no'],
            'fee_amount' => $tenant->exam_fee_amount,
            'fee_currency' => $tenant->default_currency,
            'student_marked_paid_at' => now(),
            'status' => ExamApplication::STATUS_PENDING,
        ]);
    }

    public function approve(ExamApplication $application, User $admin): ExamApplication
    {
        return DB::transaction(function () use ($application, $admin) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($application->status !== ExamApplication::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This exam application has already been decided.']);
            }

            $application->update([
                'status' => ExamApplication::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }

    public function reject(ExamApplication $application, string $reason, User $admin): ExamApplication
    {
        return DB::transaction(function () use ($application, $reason, $admin) {
            /** @var ExamApplication $application */
            $application = ExamApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($application->status !== ExamApplication::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This exam application has already been decided.']);
            }

            $application->update([
                'status' => ExamApplication::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }
}
