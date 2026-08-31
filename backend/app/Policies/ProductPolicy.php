<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PRODUCTS_VIEW);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission(Permissions::PRODUCTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PRODUCTS_CREATE);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission(Permissions::PRODUCTS_UPDATE);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission(Permissions::PRODUCTS_DELETE);
    }
}
