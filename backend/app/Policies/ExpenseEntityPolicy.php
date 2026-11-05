<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseEntity;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Accounting > Expense for: kept by whoever handles expenses. */
class ExpenseEntityPolicy
{
    public function viewAny(User $user): bool
    {
        // Borrowings pick "Borrowed for" from the same list.
        return $user->hasPermission(Permissions::EXPENSE_VIEW) || $user->hasPermission(Permissions::EXPENSE_CREATE) || $user->hasPermission(Permissions::BORROWINGS_VIEW);
    }

    public function view(User $user, ExpenseEntity $entity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_CREATE);
    }

    public function update(User $user, ExpenseEntity $entity): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_UPDATE);
    }

    public function delete(User $user, ExpenseEntity $entity): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_UPDATE);
    }
}
