<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;
use App\Support\Authorization\Permissions;

class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PROMOTIONS_VIEW);
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $user->hasPermission(Permissions::PROMOTIONS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PROMOTIONS_CREATE);
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $user->hasPermission(Permissions::PROMOTIONS_UPDATE);
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $user->hasPermission(Permissions::PROMOTIONS_DELETE);
    }
}
