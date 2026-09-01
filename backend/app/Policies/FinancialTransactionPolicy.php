<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Authorization\Permissions;

class FinancialTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::TRANSACTIONS_VIEW);
    }

    public function view(User $user, FinancialTransaction $transaction): bool
    {
        return $user->hasPermission(Permissions::TRANSACTIONS_VIEW);
    }

    /** Covers both a manual transfer and a manual adjustment — see FinancialTransactionController. */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::TRANSACTIONS_CREATE);
    }
}
