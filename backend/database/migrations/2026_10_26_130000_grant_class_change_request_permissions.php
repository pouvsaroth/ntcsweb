<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Class change requests (a student asks to move class, decided in
 * Approvals): backfills the view / approve / reject permissions onto every
 * school's School Admin role, matching Permissions::defaultsForSystemRoles().
 * Only additive.
 */
return new class extends Migration
{
    private const GRANTS = [
        'school-admin' => ['class-change-requests.view', 'class-change-requests.approve', 'class-change-requests.reject'],
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        foreach (self::GRANTS as $role => $slugs) {
            $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
            $rows = [];
            foreach (DB::table('roles')->whereNotNull('tenant_id')->where('slug', $role)->pluck('id') as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
                }
            }
            if ($rows !== []) {
                DB::table('permission_role')->insertOrIgnore($rows);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', self::GRANTS['school-admin'])->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->whereIn('slug', array_keys(self::GRANTS))->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->whereIn('role_id', $roleIds)->delete();
    }
};
