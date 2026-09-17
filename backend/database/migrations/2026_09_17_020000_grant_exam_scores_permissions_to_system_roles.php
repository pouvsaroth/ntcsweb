<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the new `exam-scores.*` permissions (see
 * Permissions::EXAM_SCORES_VIEW etc.) onto every tenant's existing system
 * roles — same reasoning as
 * 2026_09_16_100000_grant_resignation_requests_permissions_to_school_admin_role:
 * RolePermissionSeeder only grants defaults when a role is first created.
 * School admins get all three; teachers get view/update (own classes only).
 * Only additive.
 */
return new class extends Migration
{
    private const GRANTS = [
        'school-admin' => ['exam-scores.view', 'exam-scores.update', 'exam-scores.manage-all'],
        'teacher' => ['exam-scores.view', 'exam-scores.update'],
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
            ->whereIn('slug', ['exam-scores.view', 'exam-scores.update', 'exam-scores.manage-all'])
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
