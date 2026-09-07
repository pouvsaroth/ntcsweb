<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The generic eApprovals submission/decision flow — see ApprovalRequest's
 * own docblock. Unlike LeaveRequestService, approve()/reject() have no
 * side effect beyond the row itself.
 */
final class ApprovalRequestService
{
    /**
     * @param  array{subject:string, details?:string|null}  $data
     */
    public function submit(FormTemplate $template, User $user, array $data): ApprovalRequest
    {
        return ApprovalRequest::query()->create([
            'form_template_id' => $template->id,
            'requested_by' => $user->getKey(),
            'subject' => $data['subject'],
            'details' => $data['details'] ?? null,
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);
    }

    public function approve(ApprovalRequest $request, User $approver): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approver) {
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
    }

    public function reject(ApprovalRequest $request, string $reason, User $approver): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $reason, $approver) {
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
    }
}
