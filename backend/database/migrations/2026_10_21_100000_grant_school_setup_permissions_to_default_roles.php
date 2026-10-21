<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * School setup (docs/academics/school-setup.md): backfills the new
 * permissions onto every school's existing system roles, matching
 * Permissions::defaultsForSystemRoles() — all of them for school-admin, the
 * view ones for teacher and staff. Only additive.
 */
return new class extends Migration
{
    private const ADMIN = [
        'academic-terms.view', 'academic-terms.manage',
        'grade-levels.view', 'grade-levels.manage',
        'curriculum.view', 'curriculum.manage',
        'school-calendar.view', 'school-calendar.manage',
        'promotion-rules.view', 'promotion-rules.manage',
        'student-promotions.view', 'student-promotions.run',
    ];

    private const VIEW = ['academic-terms.view', 'grade-levels.view', 'curriculum.view', 'school-calendar.view'];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $this->grant('school-admin', self::ADMIN);
        $this->grant('teacher', self::VIEW);
        $this->grant('staff', self::VIEW);
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', self::ADMIN)->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->whereIn('slug', ['school-admin', 'teacher', 'staff'])->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->whereIn('role_id', $roleIds)->delete();
    }

    /** @param list<string> $slugs */
    private function grant(string $role, array $slugs): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        $roleIds = DB::table('roles')->whereNotNull('tenant_id')->where('slug', $role)->pluck('id');

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }
};
