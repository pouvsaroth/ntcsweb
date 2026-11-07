<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalGroup;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Approval Flow → Groups. The Flow setting tab lists the groups too, so its
 * own View lets a role read them without seeing the Groups tab.
 */
class ApprovalGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_VIEW) || $user->hasPermission(Permissions::APPROVAL_FLOWS_VIEW);
    }

    public function view(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_VIEW) || $user->hasPermission(Permissions::APPROVAL_FLOWS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_CREATE);
    }

    public function update(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_UPDATE);
    }

    public function delete(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_DELETE);
    }
}
