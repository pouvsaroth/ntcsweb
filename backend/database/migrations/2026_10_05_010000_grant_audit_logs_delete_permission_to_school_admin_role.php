<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new `audit-logs.delete` permission (see
 * Permissions::AUDIT_LOGS_DELETE) onto every tenant's existing
 * "school-admin" role — same reasoning as
 * 2026_09_30_020000_grant_database_backups_permission_to_school_admin_role:
 * RolePermissionSeeder only grants a system role the catalog's defaults the
 * moment that role is first created, so without this an existing school
 * admin would never see the Audit Log page's "Clear logs" button until
 * someone ticked it in the Role editor. Only additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['audit-logs.delete'])
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
            ->whereIn('slug', ['audit-logs.delete'])
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
