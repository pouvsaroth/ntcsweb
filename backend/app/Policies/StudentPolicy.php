<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Student uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Student unreachable before a policy method runs — these only
 * check the permission itself.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_VIEW);
    }

    public function view(User $user, Student $student): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_CREATE);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_UPDATE);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_DELETE);
    }

    public function approveRegistration(User $user, Student $student): bool
    {
        return $user->hasPermission(Permissions::STUDENTS_APPROVE_REGISTRATION);
    }
}
