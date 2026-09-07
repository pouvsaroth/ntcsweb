<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExamApplication;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * ExamApplication uses BelongsToTenant, so its global scope already makes a
 * cross-tenant row unreachable before a policy method runs — these only
 * check the permission itself. Submitting one's own application and viewing
 * it is gated separately, by identity (MyExamApplicationController — a
 * signed-in student needs no permission to file their own), not by
 * anything here.
 */
class ExamApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::EXAM_APPLICATIONS_VIEW);
    }

    public function view(User $user, ExamApplication $examApplication): bool
    {
        return $user->hasPermission(Permissions::EXAM_APPLICATIONS_VIEW);
    }

    public function approve(User $user, ExamApplication $examApplication): bool
    {
        return $user->hasPermission(Permissions::EXAM_APPLICATIONS_APPROVE);
    }

    public function reject(User $user, ExamApplication $examApplication): bool
    {
        return $user->hasPermission(Permissions::EXAM_APPLICATIONS_REJECT);
    }
}
