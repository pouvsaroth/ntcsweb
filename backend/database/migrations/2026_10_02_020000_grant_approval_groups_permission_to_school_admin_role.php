<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new `approval-groups.manage` permission (see
 * Permissions::APPROVAL_GROUPS_MANAGE) onto every tenant's existing
 * "school-admin" role — same reasoning as
 * 2026_09_30_010000_grant_make_up_class_requests_permissions_to_school_admin_role.
 * Only additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')->where('slug', 'approval-groups.manage')->pluck('id');

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
        $permissionIds = DB::table('permissions')->where('slug', 'approval-groups.manage')->pluck('id');

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
