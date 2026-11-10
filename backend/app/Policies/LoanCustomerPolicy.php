<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoanCustomer;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Loan > Customers: loan-customers.view / create / update / delete. */
class LoanCustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::LOAN_CUSTOMERS_VIEW);
    }

    public function view(User $user, LoanCustomer $customer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::LOAN_CUSTOMERS_CREATE);
    }

    public function update(User $user, LoanCustomer $customer): bool
    {
        return $user->hasPermission(Permissions::LOAN_CUSTOMERS_UPDATE);
    }

    public function delete(User $user, LoanCustomer $customer): bool
    {
        return $user->hasPermission(Permissions::LOAN_CUSTOMERS_DELETE);
    }
}
