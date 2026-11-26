<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommuneMember;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Political Party > Commune members: commune-members.view / create / update / delete. */
class CommuneMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::COMMUNE_MEMBERS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::COMMUNE_MEMBERS_CREATE);
    }

    public function update(User $user, CommuneMember $member): bool
    {
        return $user->hasPermission(Permissions::COMMUNE_MEMBERS_UPDATE);
    }

    public function delete(User $user, CommuneMember $member): bool
    {
        return $user->hasPermission(Permissions::COMMUNE_MEMBERS_DELETE);
    }
}
