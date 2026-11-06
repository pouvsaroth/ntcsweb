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
        // Book, book category, course package and class forms and new enrollments pick a program.
        return $user->hasAnyPermission(Permissions::ACADEMIC_PROGRAMS_VIEW, Permissions::BOOKS_CREATE, Permissions::BOOKS_UPDATE, Permissions::BOOK_CATEGORIES_CREATE, Permissions::BOOK_CATEGORIES_UPDATE, Permissions::COURSE_PACKAGES_CREATE, Permissions::COURSE_PACKAGES_UPDATE, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE, Permissions::ENROLLMENTS_CREATE);
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
