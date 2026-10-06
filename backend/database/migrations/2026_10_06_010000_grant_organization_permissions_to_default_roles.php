<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new `organization.*` permissions (HRM > Organization
 * Management — see Permissions::ORGANIZATION_VIEW etc.) onto every tenant's
 * existing roles, matching Permissions::defaultsForSystemRoles(): all four
 * for "school-admin", view-only for "teacher" and "staff" (who already see
 * Positions the same way). Same reasoning as
 * 2026_10_03_010000_grant_downloads_permissions_to_school_admin_role.
 * Only additive.
 */
return new class extends Migration
{
    private const GRANTS = [
        'school-admin' => ['organization.view', 'organization.create', 'organization.update', 'organization.delete'],
        'teacher' => ['organization.view'],
        'staff' => ['organization.view'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $rows = [];
        foreach (self::GRANTS as $roleSlug => $slugs) {
            $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
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
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'organization.%')->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
