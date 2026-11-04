<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Authorization\Permissions;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_VIEW);
    }

    public function view(User $user, Payment $payment): bool
    {
        // A company invoice's payment has no student — a missing student on
        // both sides must never count as a match.
        return $user->hasPermission(Permissions::PAYMENTS_VIEW)
            || ($payment->student_id !== null && $user->student?->id === $payment->student_id);
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_CREATE);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_UPDATE);
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Permissions::PAYMENTS_CANCEL);
    }
}
