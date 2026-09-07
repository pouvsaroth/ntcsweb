<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentFeedback;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * StudentFeedback uses BelongsToTenant, so its global scope already makes a
 * cross-tenant row unreachable before a policy method runs — these only
 * check the permission itself. Submitting one's own feedback, viewing it,
 * and replying to it is gated separately, by identity
 * (MyStudentFeedbackController — a signed-in student needs no permission
 * for their own thread), not by anything here.
 */
class StudentFeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STUDENT_FEEDBACK_VIEW);
    }

    public function view(User $user, StudentFeedback $studentFeedback): bool
    {
        return $user->hasPermission(Permissions::STUDENT_FEEDBACK_VIEW);
    }

    public function reply(User $user, StudentFeedback $studentFeedback): bool
    {
        return $user->hasPermission(Permissions::STUDENT_FEEDBACK_REPLY);
    }
}
