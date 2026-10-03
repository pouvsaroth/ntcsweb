<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\MakeUpClassRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's own make-up class request — see MakeUpClassRequest's own
 * docblock for its two approval stages. Both are purely status changes,
 * same shape as ResignationRequestService; only the second (approve())
 * makes the hours count.
 */
final class MakeUpClassRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
    ) {}

    /**
     * @param  array{enrollment_id:int, from_date:string, to_date:string, from_time:string, to_time:string}  $data
     */
    public function submit(Student $student, array $data): MakeUpClassRequest
    {
        $request = DB::transaction(function () use ($student, $data) {
            return MakeUpClassRequest::query()->create([
                'student_id' => $student->id,
                'enrollment_id' => $data['enrollment_id'],
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'from_time' => $data['from_time'],
                'to_time' => $data['to_time'],
                'status' => MakeUpClassRequest::STATUS_PENDING,
            ]);
        });

        // Outside the transaction — a notification that fails to write is
        // never worth rolling back an already-submitted request over.
        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  Collection<int, User>  $recipients
     */
    public function notifyApprovers(MakeUpClassRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::MAKE_UP_CLASS_REQUEST_SUBMITTED,
            ['student_id' => $request->student_id, 'student_name' => $request->student?->fullName(), 'make_up_class_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );
    }

    /**
     * Approved make-up hours per enrollment — every day from from_date to
     * to_date (inclusive) counts the same from_time–to_time span. Pending or
     * rejected requests never count. With a date range, only the days of a
     * request that fall inside it count (same range the Attendance Summary
     * applies to attendance records).
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, float> enrollment id -> hours
     */
    public function approvedHoursByEnrollment(array $enrollmentIds, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $hours = [];
        $rangeFrom = $dateFrom !== null ? Carbon::parse($dateFrom)->startOfDay() : null;
        $rangeTo = $dateTo !== null ? Carbon::parse($dateTo)->startOfDay() : null;

        MakeUpClassRequest::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('status', MakeUpClassRequest::STATUS_APPROVED)
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('to_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('from_date', '<=', $dateTo))
            ->get()
            ->each(function (MakeUpClassRequest $request) use (&$hours, $rangeFrom, $rangeTo) {
                $from = $rangeFrom !== null && $request->from_date->lt($rangeFrom) ? $rangeFrom : $request->from_date;
                $to = $rangeTo !== null && $request->to_date->gt($rangeTo) ? $rangeTo : $request->to_date;
                $days = (int) $from->diffInDays($to) + 1;
                $minutes = abs(Carbon::parse($request->to_time)->diffInMinutes(Carbon::parse($request->from_time)));

                $hours[$request->enrollment_id] = ($hours[$request->enrollment_id] ?? 0) + $days * $minutes / 60;
            });

        return $hours;
    }

    /**
     * Stage one — the student may come and study on the requested days. A
     * status change only; nothing counts until approve().
     */
    public function approveToStudy(MakeUpClassRequest $request, User $admin): MakeUpClassRequest
    {
        $request = $this->moveStatus($request, MakeUpClassRequest::STATUS_PENDING, MakeUpClassRequest::STATUS_APPROVED_TO_STUDY, $admin);

        $this->notifyStudent($request, NotificationType::MAKE_UP_CLASS_REQUEST_APPROVED_TO_STUDY);

        return $request;
    }

    /**
     * Stage two — the student came at the set time, so the make-up hours
     * now count (see approvedHoursByEnrollment()).
     */
    public function approve(MakeUpClassRequest $request, User $admin): MakeUpClassRequest
    {
        $request = $this->moveStatus($request, MakeUpClassRequest::STATUS_APPROVED_TO_STUDY, MakeUpClassRequest::STATUS_APPROVED, $admin);

        $this->notifyStudent($request, NotificationType::MAKE_UP_CLASS_REQUEST_APPROVED);

        return $request;
    }

    private function moveStatus(MakeUpClassRequest $request, string $from, string $to, User $admin): MakeUpClassRequest
    {
        return DB::transaction(function () use ($request, $from, $to, $admin) {
            /** @var MakeUpClassRequest $request */
            $request = MakeUpClassRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== $from) {
                throw ValidationException::withMessages(['status' => $request->status === MakeUpClassRequest::STATUS_PENDING
                    ? 'This make-up class request has not been approved to study yet.'
                    : 'This make-up class request has already been decided.']);
            }

            $request->update([
                'status' => $to,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    private function notifyStudent(MakeUpClassRequest $request, string $type): void
    {
        $studentUser = $request->student?->user;

        if ($studentUser === null) {
            return;
        }

        $this->notifications->notifyMany(collect([$studentUser]), $type, [
            'student_id' => $request->student_id,
            'student_name' => $request->student?->fullName(),
            'make_up_class_request_id' => $request->id,
        ], link: '/admin/approvals/my-requests');
    }

    public function reject(MakeUpClassRequest $request, string $reason, User $admin): MakeUpClassRequest
    {
        $request = DB::transaction(function () use ($request, $reason, $admin) {
            /** @var MakeUpClassRequest $request */
            $request = MakeUpClassRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            // Either stage — e.g. the student never came on the day.
            if (! in_array($request->status, [MakeUpClassRequest::STATUS_PENDING, MakeUpClassRequest::STATUS_APPROVED_TO_STUDY], true)) {
                throw ValidationException::withMessages(['status' => 'This make-up class request has already been decided.']);
            }

            $request->update([
                'status' => MakeUpClassRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $studentUser = $request->student?->user;
        if ($studentUser !== null) {
            $this->notifications->notifyMany(collect([$studentUser]), NotificationType::MAKE_UP_CLASS_REQUEST_REJECTED, [
                'student_id' => $request->student_id,
                'student_name' => $request->student?->fullName(),
                'make_up_class_request_id' => $request->id,
                'reason' => $reason,
            ], link: '/admin/approvals/my-requests');
        }

        return $request;
    }
}
