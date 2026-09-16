<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the three new `resignation-requests.*` permissions (see
 * Permissions::RESIGNATION_REQUESTS_VIEW etc.) onto every tenant's existing
 * "school-admin" role — same reasoning as
 * 2026_09_16_060000_grant_promotions_permissions_to_school_admin_role.
 * RolePermissionSeeder only grants a system role the catalog's defaults the
 * moment that role is first created, so a school whose school-admin role
 * predates this permission group would otherwise need someone to manually
 * check these boxes in the Role editor before the resignation approval
 * queue becomes usable. Only additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['resignation-requests.view', 'resignation-requests.approve', 'resignation-requests.reject'])
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereNotNull('tenant_id')
            ->where('slug', 'school-admin')
            ->pluck('id');

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['resignation-requests.view', 'resignation-requests.approve', 'resignation-requests.reject'])
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereNotNull('tenant_id')
            ->where('slug', 'school-admin')
            ->pluck('id');

        DB::table('permission_role')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }
};
