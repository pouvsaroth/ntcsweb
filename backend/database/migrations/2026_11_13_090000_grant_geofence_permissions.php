<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** HRM > Attendance & Time > Geofences: School Admin roles get its Add / Update / Delete. */
return new class extends Migration
{
    private const SLUGS = ['geofences.create', 'geofences.update', 'geofences.delete'];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();
        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
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
        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
    }
};
