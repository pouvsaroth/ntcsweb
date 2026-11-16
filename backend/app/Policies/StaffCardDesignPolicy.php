<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffCardDesign;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** HRM > Staff cards. */
class StaffCardDesignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_CARDS_VIEW);
    }

    public function view(User $user, StaffCardDesign $design): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_CARDS_CREATE);
    }

    public function update(User $user, StaffCardDesign $design): bool
    {
        return $user->hasPermission(Permissions::STAFF_CARDS_UPDATE);
    }

    public function delete(User $user, StaffCardDesign $design): bool
    {
        return $user->hasPermission(Permissions::STAFF_CARDS_DELETE);
    }
}
