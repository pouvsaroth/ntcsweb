<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormTemplate;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Browsing the catalog (index) is deliberately unguarded in
 * FormTemplateController — every authenticated user needs to see it to
 * submit a request. Only managing the catalog itself is permission-gated.
 */
class FormTemplatePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::FORM_TEMPLATES_CREATE);
    }

    public function update(User $user, FormTemplate $formTemplate): bool
    {
        return $user->hasPermission(Permissions::FORM_TEMPLATES_UPDATE);
    }

    public function delete(User $user, FormTemplate $formTemplate): bool
    {
        return $user->hasPermission(Permissions::FORM_TEMPLATES_DELETE);
    }
}
