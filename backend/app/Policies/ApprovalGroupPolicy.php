<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalGroup;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Approval Flow → Groups: one permission covers viewing and managing them. */
class ApprovalGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE);
    }

    public function view(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE);
    }

    public function update(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE);
    }

    public function delete(User $user, ApprovalGroup $approvalGroup): bool
    {
        return $user->hasPermission(Permissions::APPROVAL_GROUPS_MANAGE);
    }
}
