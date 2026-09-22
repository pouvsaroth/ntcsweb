<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the three new `dashboard.*` card-visibility permissions (see
 * Permissions::DASHBOARD_ATTENDANCE_VIEW etc.) onto every tenant's existing
 * school-admin/teacher/staff roles — same reasoning as
 * 2026_09_17_020000_grant_exam_scores_permissions_to_system_roles:
 * RolePermissionSeeder only grants a system role the catalog's defaults the
 * moment that role is first created. Before this migration, Dashboard.vue
 * showed the attendance/financial/payment-alerts cards to any role that
 * could reach the dashboard at all, with no permission gating them — so
 * every existing role here must keep seeing them until a school admin
 * deliberately unchecks one in the Role editor. Only additive.
 */
return new class extends Migration
{
    private const GRANTS = [
        'school-admin' => ['dashboard.attendance.view', 'dashboard.finance.view', 'dashboard.payment-alerts.view'],
        'teacher' => ['dashboard.attendance.view', 'dashboard.finance.view', 'dashboard.payment-alerts.view'],
        'staff' => ['dashboard.attendance.view', 'dashboard.finance.view', 'dashboard.payment-alerts.view'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $rows = [];

        foreach (self::GRANTS as $roleSlug => $permissionSlugs) {
            $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');
            $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', $roleSlug)->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
                }
            }
        }

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['dashboard.attendance.view', 'dashboard.finance.view', 'dashboard.payment-alerts.view'])
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereNotNull('tenant_id')
            ->whereIn('slug', array_keys(self::GRANTS))
            ->pluck('id');

        DB::table('permission_role')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }
};
