<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Political Party > Commune members (owner, 2026-11-26): each commune's full
 * and reserve members, picked from HRM staff, per election year. A Core menu (every school, no plan module); School Admin roles
 * get its permissions.
 */
return new class extends Migration
{
    private const GRANTS = [
        'school-admin' => ['commune-members.view', 'commune-members.create', 'commune-members.update', 'commune-members.delete'],
    ];

    public function up(): void
    {
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
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'commune-members.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
