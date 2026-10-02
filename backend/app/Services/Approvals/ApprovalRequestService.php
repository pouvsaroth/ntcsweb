<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\FormTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The generic eApprovals submission/decision flow — see ApprovalRequest's
 * own docblock. Unlike LeaveRequestService, approve()/reject() have no
 * side effect beyond the row itself (and notifying the requester).
 */
final class ApprovalRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{subject:string, details?:string|null}  $data
     */
    public function submit(FormTemplate $template, User $user, array $data): ApprovalRequest
    {
        $request = ApprovalRequest::query()->create([
            'form_template_id' => $template->id,
            'requested_by' => $user->getKey(),
            'subject' => $data['subject'],
            'details' => $data['details'] ?? null,
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->notifications->notifyMany(
            $this->notifications->usersWithPermission(Permissions::APPROVAL_REQUESTS_APPROVE),
            NotificationType::APPROVAL_REQUEST_SUBMITTED,
            ['requester_name' => $user->name, 'subject' => $request->subject, 'approval_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );

        return $request;
    }

    public function approve(ApprovalRequest $request, User $approver): ApprovalRequest
    {
        $request = DB::transaction(function () use ($request, $approver) {
            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $request->update([
                'status' => ApprovalRequest::STATUS_APPROVED,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyRequester($request, NotificationType::APPROVAL_REQUEST_APPROVED);

        return $request;
    }

    public function reject(ApprovalRequest $request, string $reason, User $approver): ApprovalRequest
    {
        $request = DB::transaction(function () use ($request, $reason, $approver) {
            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $request->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyRequester($request, NotificationType::APPROVAL_REQUEST_REJECTED, ['reason' => $reason]);

        return $request;
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyRequester(ApprovalRequest $request, string $type, array $extra = []): void
    {
        $requester = $request->requester;

        if ($requester === null) {
            return;
        }

        $this->notifications->notifyMany(collect([$requester]), $type, [
            'requester_name' => $requester->name,
            'subject' => $request->subject,
            'approval_request_id' => $request->id,
            ...$extra,
        ], link: '/admin/approvals/my-requests');
    }
}
