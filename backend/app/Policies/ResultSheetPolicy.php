<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResultSheet;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Who decides results submitted for approval when the item has no Approval Flow of its own
 * (ApprovalFlow::mayDecide). See docs/academics/gradebook.md.
 */
final class ResultSheetPolicy
{
    public function approve(User $user, ResultSheet $document): bool
    {
        return $user->hasPermission(Permissions::GRADEBOOK_APPROVE);
    }

    public function reject(User $user, ResultSheet $document): bool
    {
        return $user->hasPermission(Permissions::GRADEBOOK_APPROVE);
    }
}
