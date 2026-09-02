<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_YEARS_VIEW);
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_YEARS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_YEARS_CREATE);
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_YEARS_UPDATE);
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_YEARS_DELETE);
    }
}
