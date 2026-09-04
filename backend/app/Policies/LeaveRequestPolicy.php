<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * LeaveRequest uses BelongsToTenant, so its global scope already makes a
 * cross-tenant row unreachable before a policy method runs — these only
 * check the permission itself. Submitting one's own request is gated
 * separately, by identity (MyLeaveRequestController — a signed-in student
 * needs no permission to file their own), not by anything here.
 */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::LEAVE_REQUESTS_VIEW);
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->hasPermission(Permissions::LEAVE_REQUESTS_VIEW);
    }

    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->hasPermission(Permissions::LEAVE_REQUESTS_APPROVE);
    }

    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->hasPermission(Permissions::LEAVE_REQUESTS_REJECT);
    }
}
