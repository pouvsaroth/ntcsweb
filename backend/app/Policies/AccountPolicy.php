<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ACCOUNTS_VIEW);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasPermission(Permissions::ACCOUNTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ACCOUNTS_CREATE);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasPermission(Permissions::ACCOUNTS_UPDATE);
    }

    public function deactivate(User $user, Account $account): bool
    {
        return $user->hasPermission(Permissions::ACCOUNTS_DEACTIVATE);
    }
}
