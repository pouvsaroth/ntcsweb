<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcademicProgram;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AcademicProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_PROGRAMS_VIEW);
    }

    public function view(User $user, AcademicProgram $academicProgram): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_PROGRAMS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_PROGRAMS_CREATE);
    }

    public function update(User $user, AcademicProgram $academicProgram): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_PROGRAMS_UPDATE);
    }

    public function delete(User $user, AcademicProgram $academicProgram): bool
    {
        return $user->hasPermission(Permissions::ACADEMIC_PROGRAMS_DELETE);
    }
}
