<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Front Office > Meetings. Writing the meeting note or marking it done counts as updating it. */
class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_VIEW);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_CREATE);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_UPDATE);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission(Permissions::MEETINGS_DELETE);
    }
}
