<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Gates the admin/approver queue only — submitting one's own request and
 * viewing one's own requests is identity-gated in MyApprovalRequestController,
 * the same pattern as LeaveRequest. ApprovalRequest uses BelongsToTenant, so
 * its global scope already makes a cross-tenant row unreachable before a
 * policy method runs.
 */
class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_REQUESTS_VIEW);
    }

    public function view(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_REQUESTS_VIEW);
    }

    public function approve(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_REQUESTS_APPROVE);
    }

    public function reject(User $user, ApprovalRequest $approvalRequest): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_REQUESTS_REJECT);
    }
}
