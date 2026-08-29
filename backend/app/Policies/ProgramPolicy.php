<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Program;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PROGRAMS_VIEW);
    }

    public function view(User $user, Program $program): bool
    {
        return $user->hasPermission(Permissions::PROGRAMS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PROGRAMS_CREATE);
    }

    public function update(User $user, Program $program): bool
    {
        return $user->hasPermission(Permissions::PROGRAMS_UPDATE);
    }

    public function delete(User $user, Program $program): bool
    {
        return $user->hasPermission(Permissions::PROGRAMS_DELETE);
    }
}
