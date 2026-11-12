<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Dashboard > Marketing card (leads and enrolled this month): School Admin roles get it. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistry::class)->sync();
        $permissionId = DB::table('permissions')->where('slug', 'dashboard.cards.marketing')->value('id');
        if ($permissionId === null) {
            return;
        }
        $rows = DB::table('roles')->whereNotNull('tenant_id')->where('slug', 'school-admin')->pluck('id')
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all();
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'dashboard.cards.marketing')->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
    }
};
