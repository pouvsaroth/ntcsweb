<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MakeUpClassRequest;
use App\Models\User;
use App\Support\Authorization\Permissions;

class MakeUpClassRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::MAKE_UP_CLASS_REQUESTS_VIEW);
    }

    public function view(User $user, MakeUpClassRequest $makeUpClassRequest): bool
    {
        return $user->hasPermission(Permissions::MAKE_UP_CLASS_REQUESTS_VIEW);
    }

    public function approve(User $user, MakeUpClassRequest $makeUpClassRequest): bool
    {
        return $user->hasPermission(Permissions::MAKE_UP_CLASS_REQUESTS_APPROVE);
    }

    public function reject(User $user, MakeUpClassRequest $makeUpClassRequest): bool
    {
        return $user->hasPermission(Permissions::MAKE_UP_CLASS_REQUESTS_REJECT);
    }
}
