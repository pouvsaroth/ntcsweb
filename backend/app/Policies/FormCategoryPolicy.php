<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormCategory;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Browsing the catalog (index) is deliberately unguarded in
 * FormCategoryController — every authenticated user needs to see it to
 * submit a request, the same reasoning as LeaveRequest submission. Only
 * managing the catalog itself is permission-gated.
 */
class FormCategoryPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::FORM_CATEGORIES_MANAGE);
    }

    public function update(User $user, FormCategory $formCategory): bool
    {
        return $user->hasPermission(Permissions::FORM_CATEGORIES_MANAGE);
    }

    public function delete(User $user, FormCategory $formCategory): bool
    {
        return $user->hasPermission(Permissions::FORM_CATEGORIES_MANAGE);
    }
}
