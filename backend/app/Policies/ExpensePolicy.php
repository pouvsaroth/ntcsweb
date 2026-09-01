<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Segregation of duties for approve() is enforced in ExpenseService, not
 * here — a policy answers "can this user approve expenses at all", the
 * service answers "can this user approve *this* expense" (never their own).
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_VIEW);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_CREATE);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_UPDATE);
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_APPROVE);
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_REJECT);
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_PAY);
    }

    public function cancel(User $user, Expense $expense): bool
    {
        return $user->hasPermission(Permissions::EXPENSE_CANCEL);
    }
}
