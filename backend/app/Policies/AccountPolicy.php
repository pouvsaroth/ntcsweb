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
        // Expense/income/transfer/adjustment forms, Pay expense and Complete repair pick an account.
        return $user->hasAnyPermission(Permissions::ACCOUNTS_VIEW, Permissions::ACCOUNTS_CREATE, Permissions::ACCOUNTS_UPDATE, Permissions::EXPENSE_CREATE, Permissions::EXPENSE_UPDATE, Permissions::EXPENSE_PAY, Permissions::INCOME_CREATE, Permissions::INCOME_UPDATE, Permissions::TRANSACTIONS_CREATE, Permissions::ACCOUNTING_ADJUSTMENT_CREATE, Permissions::ASSET_REPAIRS_COMPLETE);
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
