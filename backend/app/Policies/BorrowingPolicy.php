<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lender;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Accounting > Borrowings: borrowings.view to read; each tab has its own
 * Add / Update / Delete — Loans (borrowing-loans.*) and Lenders (lenders.*).
 * Repayments (borrowing-repayments.create|delete) are checked in
 * BorrowingController. A create names its model: authorize('create', [Lender::class, Lender::class]).
 */
class BorrowingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BORROWINGS_VIEW);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user, ?string $class = null): bool
    {
        return $user->hasPermission($class === Lender::class ? Permissions::LENDERS_CREATE : Permissions::BORROWING_LOANS_CREATE);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->hasPermission($model instanceof Lender ? Permissions::LENDERS_UPDATE : Permissions::BORROWING_LOANS_UPDATE);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->hasPermission($model instanceof Lender ? Permissions::LENDERS_DELETE : Permissions::BORROWING_LOANS_DELETE);
    }
}
