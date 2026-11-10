<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavingsAccount;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Loan > Savings: savings.view / create (open accounts, record deposits
 * and withdrawals) / update (edit, close) / delete (entries, empty accounts).
 */
class SavingsAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::SAVINGS_VIEW);
    }

    public function view(User $user, SavingsAccount $account): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::SAVINGS_CREATE);
    }

    /** Deposits and withdrawals. */
    public function record(User $user, SavingsAccount $account): bool
    {
        return $user->hasPermission(Permissions::SAVINGS_CREATE);
    }

    public function update(User $user, SavingsAccount $account): bool
    {
        return $user->hasPermission(Permissions::SAVINGS_UPDATE);
    }

    public function delete(User $user, SavingsAccount $account): bool
    {
        return $user->hasPermission(Permissions::SAVINGS_DELETE);
    }
}
