<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use App\Support\Authorization\Permissions;

class EnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_VIEW);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CREATE);
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_UPDATE);
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_DELETE);
    }

    public function cancel(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CANCEL);
    }

    public function transfer(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_TRANSFER);
    }
}
