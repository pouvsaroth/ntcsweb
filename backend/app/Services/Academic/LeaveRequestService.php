<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Notifications\NotificationService;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's or staff member's own leave/permission request — see
 * LeaveRequest's own docblock for why this is a separate entity from
 * AttendanceRecord. approve() is the one place that ever writes attendance
 * on a student's behalf here (never for a staff-owned request), and it does
 * so entirely through the existing, unmodified
 * AttendanceService::recordForClass() — one call per (class, date) actually
 * affected, not a hand-rolled upsert.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceService $attendance,
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * `$student` xor `$staff` — whichever the signed-in account is linked
     * to; see MyLeaveRequestController::requesterOrFail().
     *
     * A staff member's request is checked against its leave type and policy
     * (see LeaveBalanceService::check()) with their staff row locked, so two
     * requests sent at once can't both spend the same balance. `$byHr`: HR
     * filing it on their behalf (HRM > Leave Management > Leave request).
     *
     * @param  array{leave_type_id?:int|null, day_part?:string|null, from_date:string, to_date:string, from_time?:string|null, to_time?:string|null, reason:string, attachments?:list<UploadedFile>}  $data
     */
    public function submit(?Student $student, ?Staff $staff, array $data, bool $byHr = false): LeaveRequest
    {
        $request = DB::transaction(function () use ($student, $staff, $data, $byHr) {
            $leave = ['leave_type_id' => null, 'day_part' => null, 'days' => null];
            if ($staff !== null) {
                $staff = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
                $leave = $this->balances->check($staff, [...$data, 'has_attachment' => ($data['attachments'] ?? []) !== []], $byHr);
            }

            $request = LeaveRequest::query()->create([
                'student_id' => $student?->id,
                'staff_id' => $staff?->id,
                ...$leave,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                // A half day is the day part, not clock times.
                'from_time' => $leave['day_part'] !== null && $leave['day_part'] !== LeaveRequest::DAY_FULL ? null : ($data['from_time'] ?? null),
                'to_time' => $leave['day_part'] !== null && $leave['day_part'] !== LeaveRequest::DAY_FULL ? null : ($data['to_time'] ?? null),
                'reason' => $data['reason'],
                'status' => LeaveRequest::STATUS_PENDING,
            ]);

            $tenant = $this->context->getOrFail();

            foreach ($data['attachments'] ?? [] as $file) {
                $path = $file->store($tenant->storagePath('leave-request-attachments'), 'public');

                if ($path === false) {
                    abort(500, 'Failed to store an attached file.');
                }

                $request->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                ]);
            }

            return $request->load(['attachments', 'leaveType']);
        });

        // Outside the transaction — a notification that fails to write is
        // never worth rolling back an already-submitted request over.
        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::LEAVE_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  Collection<int, User>  $recipients
     */
    public function notifyApprovers(LeaveRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::LEAVE_REQUEST_SUBMITTED,
            ['student_id' => $request->student_id, 'staff_id' => $request->staff_id, 'student_name' => $request->requesterName(), 'leave_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );
    }

    public function approve(LeaveRequest $request, User $admin): LeaveRequest
    {
        $request = DB::transaction(function () use ($request, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            // A staff-owned request has no enrollments/attendance to mark
            // Excused — approving one is just the status change below.
            if ($request->student_id !== null) {
                $this->applyToAttendance($request, $admin);
            }

            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyOnApproval($request);

        return $request;
    }

    /**
     * A student-owned request notifies: the student themselves, whoever
     * teaches them (see Student::teacherIds() — teacher and assistant
     * teacher alike), and every Staff-role account (this school's front-desk/
     * registration staff — see Role::STAFF's own docblock; there's no
     * separate "Receptionist" concept to target more narrowly than that). A
     * staff-owned request just notifies the requester themselves — there's
     * no "who teaches this staff member" equivalent to notify.
     */
    private function notifyOnApproval(LeaveRequest $request): void
    {
        $requesterName = $request->requesterName();
        $data = ['student_id' => $request->student_id, 'staff_id' => $request->staff_id, 'student_name' => $requesterName, 'leave_request_id' => $request->id];
        $link = '/admin/approvals/my-requests';

        if ($request->staff_id !== null) {
            $staffUser = $request->staff?->user;
            if ($staffUser !== null) {
                $this->notifications->notifyMany(collect([$staffUser]), NotificationType::LEAVE_REQUEST_APPROVED, $data, $link);
            }

            return;
        }

        $student = $request->student;
        $recipients = collect();

        if ($student->user !== null) {
            $recipients->push($student->user);
        }

        $teacherUserIds = Staff::query()->whereIn('id', $student->teacherIds())->pluck('user_id')->filter();
        $recipients = $recipients->merge(User::query()->whereIn('id', $teacherUserIds)->get());

        $recipients = $recipients->merge($this->notifications->usersWithRole(Role::STAFF));

        $this->notifications->notifyMany($recipients, NotificationType::LEAVE_REQUEST_APPROVED, $data, $link);
    }

    public function reject(LeaveRequest $request, string $reason, User $admin): LeaveRequest
    {
        $request = DB::transaction(function () use ($request, $reason, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            $request->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        // Only the requester — unlike an approval, a rejection changes
        // nothing their teachers or the front desk need to act on.
        $requesterUser = $request->staff_id !== null ? $request->staff?->user : $request->student?->user;
        if ($requesterUser !== null) {
            $this->notifications->notifyMany(collect([$requesterUser]), NotificationType::LEAVE_REQUEST_REJECTED, [
                'student_id' => $request->student_id,
                'staff_id' => $request->staff_id,
                'student_name' => $request->requesterName(),
                'leave_request_id' => $request->id,
                'reason' => $reason,
            ], link: '/admin/approvals/my-requests');
        }

        return $request;
    }

    public function destroyAttachment(LeaveRequest $request, int $attachmentId): void
    {
        $attachment = $request->attachments()->whereKey($attachmentId)->firstOrFail();

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
    }

    /**
     * Marks every date in the request's range as Excused, but only for a
     * class actually meeting that day — a student's active enrollments each
     * have their own weekly schedule (ClassSchedule), and a leave request
     * covering, say, a whole week should not manufacture an attendance row
     * for a day the class never had a session on.
     */
    private function applyToAttendance(LeaveRequest $request, User $admin): void
    {
        $enrollments = $request->student->enrollments()
            ->active()
            ->with('transferHistories')
            ->get();

        /** @var array<int, SchoolClass|null> $classes */
        $classes = [];

        foreach ($enrollments as $enrollment) {
            foreach (CarbonPeriod::create($request->from_date, $request->to_date) as $date) {
                // The class the student was in on that day — a leave for an
                // earlier date still lands in the class they had back then.
                $classId = $enrollment->placementOn($date->toDateString())['class_id'];
                if ($classId === null) {
                    continue;
                }

                $class = $classes[$classId] ??= SchoolClass::query()->with('schedules')->find($classId);
                if ($class === null || ! in_array($date->isoWeekday(), $class->schedules->pluck('day_of_week')->all(), true)) {
                    continue;
                }

                $this->attendance->recordForClass($class, $date->toDateString(), [[
                    'enrollment_id' => $enrollment->id,
                    'status' => AttendanceStatus::EXCUSED,
                    'remarks' => "Approved leave request #{$request->id}: {$request->reason}",
                ]], $admin);
            }
        }
    }
}
