<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Class > Students > Note: backfills `enrollments.note` onto every school's
 * School Admin, Teacher and Staff roles, matching
 * Permissions::defaultsForSystemRoles(). Only additive.
 */
return new class extends Migration
{
    private const ROLES = ['school-admin', 'teacher', 'staff'];

    private const SLUG = 'enrollments.note';

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if ($permissionId === null) {
            return;
        }
        $rows = DB::table('roles')->whereNotNull('tenant_id')->whereIn('slug', self::ROLES)->pluck('id')
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all();
        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->whereIn('slug', self::ROLES)->pluck('id');

        DB::table('permission_role')->where('permission_id', $permissionId)->whereIn('role_id', $roleIds)->delete();
    }
};
