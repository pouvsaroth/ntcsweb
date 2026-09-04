<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Video;
use App\Support\Authorization\Permissions;

/**
 * Video uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Video unreachable before a policy method runs — these only
 * check the permission itself.
 */
class VideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::VIDEOS_VIEW);
    }

    public function view(User $user, Video $video): bool
    {
        return $user->hasPermission(Permissions::VIDEOS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::VIDEOS_CREATE);
    }

    public function update(User $user, Video $video): bool
    {
        return $user->hasPermission(Permissions::VIDEOS_UPDATE);
    }

    public function delete(User $user, Video $video): bool
    {
        return $user->hasPermission(Permissions::VIDEOS_DELETE);
    }
}
