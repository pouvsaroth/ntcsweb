<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loan > Savings (owner, 2026-11-10). A plan with the Loan module that lists
 * its menus gets Savings too; School Admin roles get the Savings permissions.
 */
return new class extends Migration
{
    private const MENU = 'savings';

    private const GRANTS = [
        'school-admin' => ['savings.view', 'savings.create', 'savings.update', 'savings.delete'],
    ];

    public function up(): void
    {
        foreach (DB::table('bo_plans')->whereNotNull('menus')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            $menus = json_decode((string) $plan->menus, true) ?: [];
            if (! in_array('loan', $modules, true) || in_array(self::MENU, $menus, true)) {
                continue;
            }
            DB::table('bo_plans')->where('id', $plan->id)->update(['menus' => json_encode([...$menus, self::MENU])]);
        }

        app(PermissionRegistry::class)->sync();
        foreach (self::GRANTS as $role => $slugs) {
            $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
            $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', $role)->pluck('id');
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
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'savings.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
