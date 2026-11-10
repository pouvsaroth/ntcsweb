<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerLoan;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Loan > Owe: customer-loans.view / create (lend, and collect payments) /
 * update (edit, cancel) / delete (loans with no payments, and payments).
 */
class CustomerLoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CUSTOMER_LOANS_VIEW);
    }

    public function view(User $user, CustomerLoan $loan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CUSTOMER_LOANS_CREATE);
    }

    /** Record a payment from the customer. */
    public function collect(User $user, CustomerLoan $loan): bool
    {
        return $user->hasPermission(Permissions::CUSTOMER_LOANS_CREATE);
    }

    public function update(User $user, CustomerLoan $loan): bool
    {
        return $user->hasPermission(Permissions::CUSTOMER_LOANS_UPDATE);
    }

    public function delete(User $user, CustomerLoan $loan): bool
    {
        return $user->hasPermission(Permissions::CUSTOMER_LOANS_DELETE);
    }
}
