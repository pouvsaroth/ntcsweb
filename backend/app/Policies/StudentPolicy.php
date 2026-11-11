<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\ClassAccess;
use App\Support\Authorization\Permissions;

/**
 * Student uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Student unreachable before a policy method runs — these only
 * check the permission itself.
 */
class StudentPolicy
{
    /**
     * Roles > Data view: a student outside the user's assigned classes is
     * refused for every action on that student. null = go on to the method.
     */
    public function before(User $user, string $ability, mixed $student = null): ?bool
    {
        if ($student instanceof Student && ! app(ClassAccess::class)->canSeeStudent($user, $student)) {
            return false;
        }

        return null;
    }

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
