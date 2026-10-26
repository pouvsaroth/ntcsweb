<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassChangeRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Who decides a class change request when the item has no Approval Flow of
 * its own (ApprovalFlow::mayDecide).
 */
final class ClassChangeRequestPolicy
{
    public function approve(User $user, ClassChangeRequest $document): bool
    {
        return $user->hasPermission(Permissions::CLASS_CHANGE_REQUESTS_APPROVE);
    }

    public function reject(User $user, ClassChangeRequest $document): bool
    {
        return $user->hasPermission(Permissions::CLASS_CHANGE_REQUESTS_REJECT);
    }
}
