<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ElectionOffice;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Political Party > Election offices (and each commune's seats): election-offices.view / create / update / delete. */
class ElectionOfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ELECTION_OFFICES_VIEW);
    }

    public function view(User $user, ElectionOffice $office): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ELECTION_OFFICES_CREATE);
    }

    public function update(User $user, ElectionOffice $office): bool
    {
        return $user->hasPermission(Permissions::ELECTION_OFFICES_UPDATE);
    }

    public function delete(User $user, ElectionOffice $office): bool
    {
        return $user->hasPermission(Permissions::ELECTION_OFFICES_DELETE);
    }
}
