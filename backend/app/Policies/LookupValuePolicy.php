<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LookupValue;
use App\Models\User;
use App\Support\Authorization\Permissions;

class LookupValuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function view(User $user, LookupValue $value): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_CREATE);
    }

    /** Also allows an update that only touches `translations` — see LookupValueService, there is no narrower "translations only" write path today. */
    public function update(User $user, LookupValue $value): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_UPDATE) || $user->hasPermission(Permissions::BASE_DATA_MANAGE_TRANSLATIONS);
    }

    public function delete(User $user, LookupValue $value): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_DELETE);
    }
}
