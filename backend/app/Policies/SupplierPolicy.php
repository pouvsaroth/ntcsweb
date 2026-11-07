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
        return $user->hasAnyPermission(Permissions::SUPPLIERS_VIEW, Permissions::ASSETS_VIEW, Permissions::EXPENSE_VIEW);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasAnyPermission(Permissions::SUPPLIERS_VIEW, Permissions::ASSETS_VIEW, Permissions::EXPENSE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::SUPPLIERS_CREATE);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::SUPPLIERS_UPDATE);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::SUPPLIERS_DELETE);
    }
}
