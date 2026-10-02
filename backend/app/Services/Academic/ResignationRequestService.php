<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ResignationRequest;
use App\Models\Staff;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staff member's own resignation request — see ResignationRequest's own
 * docblock for why this is a separate entity from LeaveRequest despite the
 * similar pending/approved/rejected shape. Approving one is purely a status
 * change: it never touches Staff::status itself, same reasoning as
 * LeaveRequestService::approve() never touching Staff directly for a
 * staff-owned leave request.
 */
final class ResignationRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
    ) {}

    /**
     * @param  array{resignation_date:string, reason:string}  $data
     */
    public function submit(Staff $staff, array $data): ResignationRequest
    {
        $request = DB::transaction(function () use ($staff, $data) {
            return ResignationRequest::query()->create([
                'staff_id' => $staff->id,
                'resignation_date' => $data['resignation_date'],
                'reason' => $data['reason'],
                'status' => ResignationRequest::STATUS_PENDING,
            ]);
        });

        // Outside the transaction — a notification that fails to write is
        // never worth rolling back an already-submitted request over.
        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::RESIGNATION_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  Collection<int, User>  $recipients
     */
    public function notifyApprovers(ResignationRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::RESIGNATION_REQUEST_SUBMITTED,
            ['staff_id' => $request->staff_id, 'staff_name' => $request->staff?->fullName(), 'resignation_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );
    }

    public function approve(ResignationRequest $request, User $admin): ResignationRequest
    {
        $request = DB::transaction(function () use ($request, $admin) {
            /** @var ResignationRequest $request */
            $request = ResignationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== ResignationRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This resignation request has already been decided.']);
            }

            $request->update([
                'status' => ResignationRequest::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyOnApproval($request);

        return $request;
    }

    private function notifyOnApproval(ResignationRequest $request): void
    {
        $staffUser = $request->staff?->user;

        if ($staffUser === null) {
            return;
        }

        $this->notifications->notifyMany(collect([$staffUser]), NotificationType::RESIGNATION_REQUEST_APPROVED, [
            'staff_id' => $request->staff_id,
            'staff_name' => $request->staff?->fullName(),
            'resignation_request_id' => $request->id,
        ], link: '/admin/approvals/my-requests');
    }

    public function reject(ResignationRequest $request, string $reason, User $admin): ResignationRequest
    {
        $request = DB::transaction(function () use ($request, $reason, $admin) {
            /** @var ResignationRequest $request */
            $request = ResignationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== ResignationRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This resignation request has already been decided.']);
            }

            $request->update([
                'status' => ResignationRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $staffUser = $request->staff?->user;
        if ($staffUser !== null) {
            $this->notifications->notifyMany(collect([$staffUser]), NotificationType::RESIGNATION_REQUEST_REJECTED, [
                'staff_id' => $request->staff_id,
                'staff_name' => $request->staff?->fullName(),
                'resignation_request_id' => $request->id,
                'reason' => $reason,
            ], link: '/admin/approvals/my-requests');
        }

        return $request;
    }
}
