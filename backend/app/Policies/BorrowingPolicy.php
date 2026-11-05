<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;

/** Accounting > Borrowings: loans and lenders share one pair of permissions. */
class BorrowingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BORROWINGS_VIEW);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BORROWINGS_MANAGE);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->hasPermission(Permissions::BORROWINGS_MANAGE);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->hasPermission(Permissions::BORROWINGS_MANAGE);
    }
}
