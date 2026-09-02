<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudyMode;
use App\Models\User;
use App\Support\Authorization\Permissions;

class StudyModePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STUDY_MODES_VIEW);
    }

    public function view(User $user, StudyMode $studyMode): bool
    {
        return $user->hasPermission(Permissions::STUDY_MODES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STUDY_MODES_CREATE);
    }

    public function update(User $user, StudyMode $studyMode): bool
    {
        return $user->hasPermission(Permissions::STUDY_MODES_UPDATE);
    }

    public function delete(User $user, StudyMode $studyMode): bool
    {
        return $user->hasPermission(Permissions::STUDY_MODES_DELETE);
    }
}
