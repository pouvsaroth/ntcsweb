<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseSupport;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Accounting > Expenses > Support. */
class ExpenseSupportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_SUPPORTS_VIEW);
    }

    public function view(User $user, ExpenseSupport $support): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_SUPPORTS_CREATE);
    }

    public function update(User $user, ExpenseSupport $support): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_SUPPORTS_UPDATE);
    }

    public function delete(User $user, ExpenseSupport $support): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_SUPPORTS_DELETE);
    }
}
