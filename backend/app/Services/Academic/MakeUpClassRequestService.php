<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\MakeUpClassRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's own make-up class request — see MakeUpClassRequest's own
 * docblock. Approving one is purely a status change, same shape as
 * ResignationRequestService.
 */
final class MakeUpClassRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
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
        $this->notifications->notifyMany(
            $this->notifications->usersWithPermission(Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE),
            NotificationType::MAKE_UP_CLASS_REQUEST_SUBMITTED,
            ['student_id' => $student->id, 'student_name' => $student->fullName(), 'make_up_class_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );

        return $request;
    }

    public function approve(MakeUpClassRequest $request, User $admin): MakeUpClassRequest
    {
        $request = DB::transaction(function () use ($request, $admin) {
            /** @var MakeUpClassRequest $request */
            $request = MakeUpClassRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== MakeUpClassRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This make-up class request has already been decided.']);
            }

            $request->update([
                'status' => MakeUpClassRequest::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyOnApproval($request);

        return $request;
    }

    private function notifyOnApproval(MakeUpClassRequest $request): void
    {
        $studentUser = $request->student?->user;

        if ($studentUser === null) {
            return;
        }

        $this->notifications->notifyMany(collect([$studentUser]), NotificationType::MAKE_UP_CLASS_REQUEST_APPROVED, [
            'student_id' => $request->student_id,
            'student_name' => $request->student?->fullName(),
            'make_up_class_request_id' => $request->id,
        ], link: '/admin/approvals/my-requests');
    }

    public function reject(MakeUpClassRequest $request, string $reason, User $admin): MakeUpClassRequest
    {
        return DB::transaction(function () use ($request, $reason, $admin) {
            /** @var MakeUpClassRequest $request */
            $request = MakeUpClassRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== MakeUpClassRequest::STATUS_PENDING) {
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
    }
}
