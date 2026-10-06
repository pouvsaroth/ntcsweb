<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Overtime requests: staff-attendance view reads them all; the decision is
 * the overtime-requests approve/reject permission (ApprovalFlow::mayDecide()
 * decides instead when the item has a flow).
 */
class OvertimeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function view(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function delete(User $user, OvertimeRequest $request): bool
    {
        return $request->status === OvertimeRequest::STATUS_PENDING && $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function approve(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermission(Permissions::OVERTIME_REQUESTS_APPROVE);
    }

    public function reject(User $user, OvertimeRequest $request): bool
    {
        return $user->hasPermission(Permissions::OVERTIME_REQUESTS_REJECT);
    }
}
