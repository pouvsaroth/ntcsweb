<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Schools themselves are platform objects. Creating, suspending or deleting one
 * is a super admin action, and super admins are granted it by Gate::before
 * rather than by anything here — so every method below is written from the
 * point of view of a school administrator looking at their own school.
 */
class TenantPolicy
{
    /**
     * Listing all schools is inherently cross-tenant.
     */
    public function viewAny(User $actor): bool
    {
        return false;
    }

    public function view(User $actor, Tenant $tenant): bool
    {
        return $actor->belongsToTenant($tenant)
            && $actor->hasPermission(Permissions::TENANT_SETTINGS_VIEW);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    /**
     * A school admin may edit their own school's profile and settings, but not
     * its status, slug or domains — those are platform-level and are filtered
     * out by UpdateTenantSettingsRequest.
     */
    public function update(User $actor, Tenant $tenant): bool
    {
        return $actor->belongsToTenant($tenant)
            && $actor->hasPermission(Permissions::TENANT_SETTINGS_UPDATE);
    }

    public function delete(User $actor, Tenant $tenant): bool
    {
        return false;
    }

    public function manageDomains(User $actor, Tenant $tenant): bool
    {
        // Pointing a hostname at a school decides whose traffic goes where;
        // that stays with the platform.
        return false;
    }
}
