<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomeSlide;
use App\Models\User;
use App\Support\Authorization\Permissions;

class HomeSlidePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::HOME_SLIDES_VIEW);
    }

    public function view(User $user, HomeSlide $slide): bool
    {
        return $user->hasPermission(Permissions::HOME_SLIDES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::HOME_SLIDES_CREATE);
    }

    public function update(User $user, HomeSlide $slide): bool
    {
        return $user->hasPermission(Permissions::HOME_SLIDES_UPDATE);
    }

    public function delete(User $user, HomeSlide $slide): bool
    {
        return $user->hasPermission(Permissions::HOME_SLIDES_DELETE);
    }
}
