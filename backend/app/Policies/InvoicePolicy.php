<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\Permissions;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::INVOICES_VIEW);
    }

    /**
     * A student may always view their own invoice — no dedicated permission
     * for this: it's identity-based (their own record), not role-based, the
     * same way a self-service profile edit needs no special grant.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permissions::INVOICES_VIEW)
            || $user->student?->id === $invoice->student_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::INVOICES_CREATE);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permissions::INVOICES_UPDATE);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permissions::INVOICES_CANCEL);
    }
}
