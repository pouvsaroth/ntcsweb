<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Marketing > Referrals: plans with Marketing that list menus get it; School Admin roles get its permissions. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            if (! in_array('marketing', $modules, true) || $plan->menus === null) {
                continue;
            }
            $menus = json_decode((string) $plan->menus, true) ?: [];
            if (! in_array('referrals', $menus, true)) {
                DB::table('bo_plans')->where('id', $plan->id)->update(['menus' => json_encode([...$menus, 'referrals'])]);
            }
        }

        app(PermissionRegistry::class)->sync();
        $permissionIds = DB::table('permissions')->whereIn('slug', ['referrals.view', 'referrals.update'])->pluck('id');
        $rows = [];
        foreach (DB::table('roles')->whereNotNull('tenant_id')->where('slug', 'school-admin')->pluck('id') as $roleId) {
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
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'referrals.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
