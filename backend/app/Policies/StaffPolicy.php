<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Staff uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Staff row unreachable before a policy method runs — these only
 * check the permission itself.
 *
 * staff.update-own (for anyone but School Admin) narrows HRM > Staff to the user's
 * own record: they see only it and can edit it, every tab included, except
 * the work fields in UpdateStaffRequest::OWN_PROFILE_LOCKED.
 */
class StaffPolicy
{
    /** True when HRM > Staff is limited to the user's own record. */
    public static function ownProfileOnly(User $user): bool
    {
        // "Own staff profile only" wins over Staff view/update: such a user only ever
        // sees and edits their own record. School Admin (often given Select all) and
        // super admins are never limited by it.
        return ! $user->isSuperAdmin()
            && ! $user->hasRole(Role::SCHOOL_ADMIN)
            && $user->hasPermission(Permissions::STAFF_UPDATE_OWN);
    }

    private static function isOwn(User $user, Staff $staff): bool
    {
        return $staff->user_id !== null && $staff->user_id === $user->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_VIEW) || self::ownProfileOnly($user);
    }

    public function view(User $user, Staff $staff): bool
    {
        if (self::ownProfileOnly($user)) {
            return self::isOwn($user, $staff);
        }

        return $user->hasPermission(Permissions::STAFF_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_CREATE);
    }

    public function update(User $user, Staff $staff): bool
    {
        if (self::ownProfileOnly($user)) {
            return self::isOwn($user, $staff);
        }

        return $user->hasPermission(Permissions::STAFF_UPDATE);
    }

    public function delete(User $user, Staff $staff): bool
    {
        // Own-profile-only users never delete or change status, not even their own.
        return ! self::ownProfileOnly($user) && $user->hasPermission(Permissions::STAFF_DELETE);
    }

    public function changeStatus(User $user, Staff $staff): bool
    {
        return ! self::ownProfileOnly($user) && $user->hasPermission(Permissions::STAFF_CHANGE_STATUS);
    }
}
