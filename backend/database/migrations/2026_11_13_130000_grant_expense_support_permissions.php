<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Accounting > Expenses > Support: School Admin roles get View / Add / Update / Delete. */
return new class extends Migration
{
    private const SLUGS = ['expense-supports.view', 'expense-supports.create', 'expense-supports.update', 'expense-supports.delete'];

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
