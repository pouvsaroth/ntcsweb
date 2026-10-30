<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRM > Professional Development (docs/hrm/professional-development.md): the
 * new HRM menu goes onto every plan that has HRM and lists its menus, and
 * its permissions onto every school's School Admin role, matching
 * Permissions::defaultsForSystemRoles(). Only additive.
 */
return new class extends Migration
{
    private const MENU = 'professional-development';

    private const SLUGS = ['professional-development.view', 'professional-development.manage'];

    public function up(): void
    {
        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            if ($plan->menus === null || ! in_array('hrm', $modules, true)) {
                continue;
            }
            $menus = json_decode((string) $plan->menus, true) ?: [];
            if (! in_array(self::MENU, $menus, true)) {
                DB::table('bo_plans')->where('id', $plan->id)->update(['menus' => json_encode([...$menus, self::MENU])]);
            }
        }

        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', 'school-admin')->pluck('id');
        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
