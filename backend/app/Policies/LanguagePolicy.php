<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Language;
use App\Models\User;
use App\Support\Authorization\Permissions;

class LanguagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function view(User $user, Language $language): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_MANAGE_LANGUAGES);
    }

    public function update(User $user, Language $language): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_MANAGE_LANGUAGES);
    }

    public function delete(User $user, Language $language): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_MANAGE_LANGUAGES);
    }
}
