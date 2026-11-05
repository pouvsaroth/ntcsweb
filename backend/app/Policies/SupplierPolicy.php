<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Vendors: shared by Assets (bought from) and Expenses (paid to), so either module's permissions reach it. */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW) || $user->hasPermission(Permissions::EXPENSE_VIEW);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW) || $user->hasPermission(Permissions::EXPENSE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE) || $user->hasPermission(Permissions::EXPENSE_CREATE);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE) || $user->hasPermission(Permissions::EXPENSE_UPDATE);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE) || $user->hasPermission(Permissions::EXPENSE_UPDATE);
    }
}
