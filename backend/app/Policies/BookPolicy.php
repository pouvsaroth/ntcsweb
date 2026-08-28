<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Book;
use App\Models\User;
use App\Support\Authorization\Permissions;

class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BOOKS_VIEW);
    }

    public function view(User $user, Book $book): bool
    {
        return $user->hasPermission(Permissions::BOOKS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BOOKS_CREATE);
    }

    public function update(User $user, Book $book): bool
    {
        return $user->hasPermission(Permissions::BOOKS_UPDATE);
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->hasPermission(Permissions::BOOKS_DELETE);
    }
}
