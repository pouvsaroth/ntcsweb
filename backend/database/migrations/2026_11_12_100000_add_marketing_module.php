<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The new Marketing module (owner, 2026-11-12): Marketing > Campaigns, linked
 * to Front Office inquiries. Every system and plan that has Front Office gets
 * Marketing; a plan that lists its menus gets Campaigns. School Admin roles
 * get the Campaign permissions.
 */
return new class extends Migration
{
    private const MODULE = 'marketing';

    private const NEEDS = 'front-office';

    private const NEW_MENUS = ['campaigns'];

    private const GRANTS = [
        'school-admin' => ['campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.delete'],
    ];

    public function up(): void
    {
        foreach (DB::table('bo_products')->get(['id', 'modules']) as $product) {
            $modules = json_decode((string) $product->modules, true) ?: [];
            if (in_array(self::NEEDS, $modules, true) && ! in_array(self::MODULE, $modules, true)) {
                DB::table('bo_products')->where('id', $product->id)->update(['modules' => json_encode([...$modules, self::MODULE])]);
            }
        }

        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            if (! in_array(self::NEEDS, $modules, true) || in_array(self::MODULE, $modules, true)) {
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
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'campaigns.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
