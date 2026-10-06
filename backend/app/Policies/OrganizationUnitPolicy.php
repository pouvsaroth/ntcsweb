<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch, Team, JobGrade and JobLevel — HRM > Organization Management's
 * lists share the one organization.* permission set (see
 * AuthServiceProvider), rather than four near-identical sets of their own.
 */
class OrganizationUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_VIEW);
    }

    public function view(User $user, Model $unit): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_CREATE);
    }

    public function update(User $user, Model $unit): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_UPDATE);
    }

    public function delete(User $user, Model $unit): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_DELETE);
    }
}
