<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ManpowerRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * HRM > Recruitment > Manpower request. Editing and deleting are only for a
 * request still waiting on a decision; approve/reject are the E-Approvals
 * decision (ApprovalFlow::mayDecide() decides instead when it has a flow).
 */
class ManpowerRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function view(User $user, ManpowerRequest $request): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_CREATE);
    }

    public function update(User $user, ManpowerRequest $request): bool
    {
        return $request->status === ManpowerRequest::STATUS_PENDING && $user->hasPermission(Permissions::RECRUITMENT_UPDATE);
    }

    public function delete(User $user, ManpowerRequest $request): bool
    {
        return $request->status !== ManpowerRequest::STATUS_APPROVED && $user->hasPermission(Permissions::RECRUITMENT_DELETE);
    }

    public function approve(User $user, ManpowerRequest $request): bool
    {
        return $user->hasPermission(Permissions::MANPOWER_REQUESTS_APPROVE);
    }

    public function reject(User $user, ManpowerRequest $request): bool
    {
        return $user->hasPermission(Permissions::MANPOWER_REQUESTS_REJECT);
    }
}
