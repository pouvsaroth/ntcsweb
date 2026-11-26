<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PartyBranch;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Political Party > Branches: party-branches.view / create / update / delete. */
class PartyBranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PARTY_BRANCHES_VIEW);
    }

    public function view(User $user, PartyBranch $branch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PARTY_BRANCHES_CREATE);
    }

    public function update(User $user, PartyBranch $branch): bool
    {
        return $user->hasPermission(Permissions::PARTY_BRANCHES_UPDATE);
    }

    public function delete(User $user, PartyBranch $branch): bool
    {
        return $user->hasPermission(Permissions::PARTY_BRANCHES_DELETE);
    }
}
