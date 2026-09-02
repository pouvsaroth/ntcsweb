<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProgramOffering;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ProgramOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PROGRAM_OFFERINGS_VIEW);
    }

    public function view(User $user, ProgramOffering $programOffering): bool
    {
        return $user->hasPermission(Permissions::PROGRAM_OFFERINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PROGRAM_OFFERINGS_CREATE);
    }

    public function update(User $user, ProgramOffering $programOffering): bool
    {
        return $user->hasPermission(Permissions::PROGRAM_OFFERINGS_UPDATE);
    }

    public function delete(User $user, ProgramOffering $programOffering): bool
    {
        return $user->hasPermission(Permissions::PROGRAM_OFFERINGS_DELETE);
    }
}
