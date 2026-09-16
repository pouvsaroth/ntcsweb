<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the four new `promotions.*` permissions (see
 * Permissions::PROMOTIONS_VIEW etc.) onto every tenant's existing
 * "school-admin" role. RolePermissionSeeder only grants a system role the
 * catalog's defaults the moment that role is first created (see its own
 * docblock — an already-existing role is admin-owned from then on), so a
 * school whose school-admin role predates this permission group would
 * otherwise need someone to manually check these boxes in the Role editor
 * before the new Promotion admin page becomes reachable. Only additive:
 * never touches a role's other permissions or any custom (non-system) role.
 *
 * Runs PermissionRegistry::sync() first so the four `promotions.*` rows
 * exist in the `permissions` table regardless of whether the seeder has run
 * yet on this environment — the single source of truth for their slug/name/
 * group stays Permissions::catalog(), not a copy hardcoded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete'])
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
            ->whereIn('slug', ['promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete'])
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
