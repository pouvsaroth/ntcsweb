<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BookCategory;
use App\Models\User;
use App\Support\Authorization\Permissions;

class BookCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BOOK_CATEGORIES_VIEW);
    }

    public function view(User $user, BookCategory $bookCategory): bool
    {
        return $user->hasPermission(Permissions::BOOK_CATEGORIES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BOOK_CATEGORIES_CREATE);
    }

    public function update(User $user, BookCategory $bookCategory): bool
    {
        return $user->hasPermission(Permissions::BOOK_CATEGORIES_UPDATE);
    }

    public function delete(User $user, BookCategory $bookCategory): bool
    {
        return $user->hasPermission(Permissions::BOOK_CATEGORIES_DELETE);
    }
}
