<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The new Front Office module (owner, 2026-10-28: every school gets it):
 * added to every system and plan, and its Inquiry, Meetings, Appointments and Visitors menus to plans that list
 * their menus. Its permissions go onto every school's School Admin (all)
 * and Staff (view/record/update) roles, matching defaultsForSystemRoles().
 */
return new class extends Migration
{
    private const MODULE = 'front-office';

    private const MENUS = ['inquiries', 'meetings', 'appointments', 'visitors'];

    private const GRANTS = [
        'school-admin' => [
            'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.delete',
            'meetings.view', 'meetings.create', 'meetings.update', 'meetings.delete',
            'appointments.view', 'appointments.create', 'appointments.update', 'appointments.delete',
            'visitors.view', 'visitors.create', 'visitors.update', 'visitors.delete',
        ],
        'staff' => [
            'inquiries.view', 'inquiries.create', 'inquiries.update',
            'meetings.view', 'meetings.create', 'meetings.update',
            'appointments.view', 'appointments.create', 'appointments.update',
            'visitors.view', 'visitors.create', 'visitors.update',
        ],
        'teacher' => ['meetings.view'],
    ];

    public function up(): void
    {
        foreach (DB::table('bo_products')->get(['id', 'modules']) as $product) {
            $modules = json_decode((string) $product->modules, true) ?: [];
            if (! in_array(self::MODULE, $modules, true)) {
                DB::table('bo_products')->where('id', $product->id)->update(['modules' => json_encode([...$modules, self::MODULE])]);
            }
        }

        foreach (DB::table('bo_plans')->get(['id', 'modules', 'menus']) as $plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            $menus = $plan->menus === null ? null : (json_decode((string) $plan->menus, true) ?: []);
            if (! in_array(self::MODULE, $modules, true)) {
                $modules[] = self::MODULE;
            }
            if ($menus !== null) {
                $menus = array_values(array_unique([...$menus, ...self::MENUS]));
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
        $permissionIds = DB::table('permissions')->where(fn ($q) => $q->where('slug', 'like', 'inquiries.%')->orWhere('slug', 'like', 'meetings.%')->orWhere('slug', 'like', 'appointments.%')->orWhere('slug', 'like', 'visitors.%'))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
