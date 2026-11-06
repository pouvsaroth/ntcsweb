<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CoursePackage;
use App\Models\User;
use App\Support\Authorization\Permissions;

class CoursePackagePolicy
{
    public function viewAny(User $user): bool
    {
        // The video form, new enrollments and Change class pick a package.
        return $user->hasAnyPermission(Permissions::COURSE_PACKAGES_VIEW, Permissions::VIDEOS_CREATE, Permissions::VIDEOS_UPDATE, Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER);
    }

    public function view(User $user, CoursePackage $coursePackage): bool
    {
        return $user->hasPermission(Permissions::COURSE_PACKAGES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::COURSE_PACKAGES_CREATE);
    }

    public function update(User $user, CoursePackage $coursePackage): bool
    {
        return $user->hasPermission(Permissions::COURSE_PACKAGES_UPDATE);
    }

    public function delete(User $user, CoursePackage $coursePackage): bool
    {
        return $user->hasPermission(Permissions::COURSE_PACKAGES_DELETE);
    }
}
