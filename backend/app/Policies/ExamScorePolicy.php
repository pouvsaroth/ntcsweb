<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Only the ability itself — which applications a user may actually see or
 * score (their own classes vs. every class) is decided row-by-row in
 * ExamScoreService::scoreableQuery().
 */
class ExamScorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::EXAM_SCORES_VIEW)
            || $user->hasPermission(Permissions::EXAM_SCORES_MANAGE_ALL);
    }

    public function record(User $user): bool
    {
        return $user->hasPermission(Permissions::EXAM_SCORES_UPDATE)
            || $user->hasPermission(Permissions::EXAM_SCORES_MANAGE_ALL);
    }
}
