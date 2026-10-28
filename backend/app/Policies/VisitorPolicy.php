<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;
use App\Support\Authorization\Permissions;

/**
 * Front Office > Visitors. Recording a walk-in or checking an appointment
 * in is "create"; checking out is "update".
 */
class VisitorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::VISITORS_VIEW);
    }

    public function view(User $user, Visitor $visitor): bool
    {
        return $user->hasPermission(Permissions::VISITORS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::VISITORS_CREATE);
    }

    public function update(User $user, Visitor $visitor): bool
    {
        return $user->hasPermission(Permissions::VISITORS_UPDATE);
    }

    public function delete(User $user, Visitor $visitor): bool
    {
        return $user->hasPermission(Permissions::VISITORS_DELETE);
    }
}
