<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GradeCorrection;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Who decides grade corrections when the item has no Approval Flow of its own
 * (ApprovalFlow::mayDecide). See docs/academics/gradebook.md.
 */
final class GradeCorrectionPolicy
{
    public function approve(User $user, GradeCorrection $document): bool
    {
        return $user->hasPermission(Permissions::GRADEBOOK_APPROVE);
    }

    public function reject(User $user, GradeCorrection $document): bool
    {
        return $user->hasPermission(Permissions::GRADEBOOK_APPROVE);
    }
}
