<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FundProvider;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Fund > Providers: the Fund permissions (view / create / update / delete). */
class FundProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::FUNDS_VIEW);
    }

    public function view(User $user, FundProvider $provider): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::FUNDS_CREATE);
    }

    public function update(User $user, FundProvider $provider): bool
    {
        return $user->hasPermission(Permissions::FUNDS_UPDATE);
    }

    public function delete(User $user, FundProvider $provider): bool
    {
        return $user->hasPermission(Permissions::FUNDS_DELETE);
    }
}
