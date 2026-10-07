<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new payroll permissions (HRM > Payroll — see
 * Permissions::PAYROLL_VIEW/MANAGE) onto every tenant's existing
 * "school-admin" role, matching defaultsForSystemRoles(). Same reasoning as
 * 2026_10_07_010000_grant_leave_management_permissions_to_school_admin_role.
 * Only additive.
 */
return new class extends Migration
{
    private const SLUGS = ['payroll.view', 'payroll.manage'];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', 'school-admin')->pluck('id');

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
        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', 'school-admin')->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->whereIn('role_id', $roleIds)->delete();
    }
};
