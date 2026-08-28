<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Unlike UserPolicy/RolePolicy, Teacher uses BelongsToTenant — its global
 * query scope already makes a cross-tenant Teacher unreachable before a
 * policy method ever runs (route model binding queries through the scoped
 * model). So these methods only need to check the permission itself.
 */
class TeacherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::TEACHERS_VIEW);
    }

    public function view(User $user, Teacher $teacher): bool
    {
        return $user->hasPermission(Permissions::TEACHERS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::TEACHERS_CREATE);
    }

    public function update(User $user, Teacher $teacher): bool
    {
        return $user->hasPermission(Permissions::TEACHERS_UPDATE);
    }

    public function delete(User $user, Teacher $teacher): bool
    {
        return $user->hasPermission(Permissions::TEACHERS_DELETE);
    }
}
