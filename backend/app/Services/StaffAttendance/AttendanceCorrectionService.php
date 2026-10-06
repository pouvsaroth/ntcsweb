<?php

declare(strict_types=1);

namespace App\Services\StaffAttendance;

use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance corrections — submitted into E-Approvals, decided there; the
 * final approval writes the corrected times onto the day through
 * StaffAttendanceService::record() (so its minutes and status are worked out
 * again, and a signed-off month is still refused).
 */
final class AttendanceCorrectionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
        private readonly StaffAttendanceService $attendance,
    ) {}

    public function submit(Staff $staff, string $date, ?string $checkIn, ?string $checkOut, string $reason, User $by): AttendanceCorrection
    {
        if ($checkIn === null && $checkOut === null) {
            throw ValidationException::withMessages(['check_in' => __('Give the correct check-in or check-out time.')]);
        }
        $this->attendance->assertOpen($staff->id, $date);

        $pending = AttendanceCorrection::query()->where('staff_id', $staff->id)->whereDate('date', $date)->where('status', AttendanceCorrection::STATUS_PENDING)->exists();
        if ($pending) {
            throw ValidationException::withMessages(['date' => __('A correction for this day is already waiting for approval.')]);
        }

        $correction = AttendanceCorrection::query()->create([
            'staff_id' => $staff->id,
            'date' => $date,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'reason' => $reason,
            'requested_by' => $by->getKey(),
        ]);

        $this->notifyApprovers($correction, $this->flow->submitRecipients(
            $correction,
            fn () => $this->notifications->usersWithPermission(Permissions::ATTENDANCE_CORRECTIONS_APPROVE),
        ));

        return $correction;
    }

    /** @param  Collection<int, User>  $recipients */
    public function notifyApprovers(AttendanceCorrection $correction, Collection $recipients): void
    {
        $this->notifications->notifyMany($recipients, NotificationType::ATTENDANCE_CORRECTION_SUBMITTED, $this->data($correction), link: '/admin/approvals/queue');
    }

    public function approve(AttendanceCorrection $correction, User $admin): AttendanceCorrection
    {
        $correction = DB::connection('tenant')->transaction(function () use ($correction, $admin) {
            /** @var AttendanceCorrection $correction */
            $correction = AttendanceCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($correction);

            $this->attendance->record(
                $correction->staff,
                $correction->date->toDateString(),
                $correction->check_in,
                $correction->check_out,
                StaffAttendance::SOURCE_CORRECTION,
                __('Correction :reference: :reason', ['reference' => $correction->reference(), 'reason' => $correction->reason]),
                $admin,
            );

            $correction->update(['status' => AttendanceCorrection::STATUS_APPROVED, 'decided_by' => $admin->getKey(), 'decided_at' => now()]);

            return $correction->fresh();
        });

        $this->notifyStaff($correction, NotificationType::ATTENDANCE_CORRECTION_APPROVED);

        return $correction;
    }

    public function reject(AttendanceCorrection $correction, string $reason, User $admin): AttendanceCorrection
    {
        $correction = DB::connection('tenant')->transaction(function () use ($correction, $reason, $admin) {
            /** @var AttendanceCorrection $correction */
            $correction = AttendanceCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($correction);

            $correction->update(['status' => AttendanceCorrection::STATUS_REJECTED, 'decision_reason' => $reason, 'decided_by' => $admin->getKey(), 'decided_at' => now()]);

            return $correction->fresh();
        });

        $this->notifyStaff($correction, NotificationType::ATTENDANCE_CORRECTION_REJECTED, ['reason' => $reason]);

        return $correction;
    }

    private function assertPending(AttendanceCorrection $correction): void
    {
        if ($correction->status !== AttendanceCorrection::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This correction has already been decided.']);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyStaff(AttendanceCorrection $correction, string $type, array $extra = []): void
    {
        $user = $correction->staff?->user;
        if ($user !== null) {
            $this->notifications->notify($user, $type, [...$this->data($correction), ...$extra], link: '/admin/my-check-in');
        }
    }

    /** @return array<string, mixed> */
    private function data(AttendanceCorrection $correction): array
    {
        return [
            'attendance_correction_id' => $correction->id,
            'staff_name' => $correction->staff?->fullName(),
            'date' => $correction->date?->format('d-m-Y'),
        ];
    }
}
