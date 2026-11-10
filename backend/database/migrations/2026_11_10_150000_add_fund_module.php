<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The new Fund module (owner, 2026-11-10): Fund > Funds, pots of the school's
 * money posted to the ledger. Every system and plan that has Accounting gets
 * Fund; a plan that lists its menus gets Funds. School Admin roles get the
 * Fund permissions.
 */
return new class extends Migration
{
    private const MODULE = 'fund';

    private const NEW_MENUS = ['funds'];

    private const GRANTS = [
        'school-admin' => ['funds.view', 'funds.create', 'funds.update', 'funds.delete'],
    ];

    public function up(): void
    {
        foreach (DB::table('bo_products')->get(['id', 'modules']) as $product) {
            $modules = json_decode((string) $product->modules, true) ?: [];
            if (in_array('accounting', $modules, true) && ! in_array(self::MODULE, $modules, true)) {
                DB::table('bo_products')->where('id', $product->id)->update(['modules' => json_encode([...$modules, self::MODULE])]);
            }
        }

        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            if (! in_array('accounting', $modules, true) || in_array(self::MODULE, $modules, true)) {
                continue;
            }
            $modules[] = self::MODULE;
            $menus = $plan->menus === null ? null : (json_decode((string) $plan->menus, true) ?: []);
            if ($menus !== null) {
                $menus = array_values(array_unique([...$menus, ...self::NEW_MENUS]));
            }
            DB::table('bo_plans')->where('id', $plan->id)->update([
                'modules' => json_encode($modules),
                'menus' => $menus === null ? null : json_encode($menus),
            ]);
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
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'funds.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
