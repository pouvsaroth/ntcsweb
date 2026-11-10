<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fund;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Fund > Funds: funds.view / create (add funds, record money in and out) /
 * update (edit, close) / delete (entries, empty funds).
 */
class FundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::FUNDS_VIEW);
    }

    public function view(User $user, Fund $fund): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::FUNDS_CREATE);
    }

    /** Money in and money out. */
    public function record(User $user, Fund $fund): bool
    {
        return $user->hasPermission(Permissions::FUNDS_CREATE);
    }

    public function update(User $user, Fund $fund): bool
    {
        return $user->hasPermission(Permissions::FUNDS_UPDATE);
    }

    public function delete(User $user, Fund $fund): bool
    {
        return $user->hasPermission(Permissions::FUNDS_DELETE);
    }
}
