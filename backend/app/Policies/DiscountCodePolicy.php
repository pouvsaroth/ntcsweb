<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DiscountCode;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Invoices > Discount codes: its own View / Add / Update / Delete. */
class DiscountCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::DISCOUNT_CODES_VIEW);
    }

    public function view(User $user, DiscountCode $code): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::DISCOUNT_CODES_CREATE);
    }

    public function update(User $user, DiscountCode $code): bool
    {
        return $user->hasPermission(Permissions::DISCOUNT_CODES_UPDATE);
    }

    public function delete(User $user, DiscountCode $code): bool
    {
        return $user->hasPermission(Permissions::DISCOUNT_CODES_DELETE);
    }
}
