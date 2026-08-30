<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GalleryImage;
use App\Models\User;
use App\Support\Authorization\Permissions;

class GalleryImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::GALLERY_VIEW);
    }

    public function view(User $user, GalleryImage $image): bool
    {
        return $user->hasPermission(Permissions::GALLERY_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::GALLERY_CREATE);
    }

    public function update(User $user, GalleryImage $image): bool
    {
        return $user->hasPermission(Permissions::GALLERY_UPDATE);
    }

    public function delete(User $user, GalleryImage $image): bool
    {
        return $user->hasPermission(Permissions::GALLERY_DELETE);
    }
}
