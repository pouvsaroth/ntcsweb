<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 5a: HRM > Organization Management's lists (Branch, Department,
 * Team, Job grade, Job level) each get their own Add / Update / Delete instead
 * of one organization.create|update|delete; Departments (also Assets >
 * Departments) gets its own View too, instead of organization.* / assets.*.
 * Each new permission goes to every role that could do the same thing before;
 * roles are read before sync() drops the old organization ones.
 */
return new class extends Migration
{
    /** New permission => the old ones that allowed it. */
    private const GRANTS = [
        'departments.view' => ['organization.view', 'assets.view'],
        'branches.create' => ['organization.create'],
        'teams.create' => ['organization.create'],
        'job-grades.create' => ['organization.create'],
        'job-levels.create' => ['organization.create'],
        'departments.create' => ['organization.create', 'assets.create'],
        'branches.update' => ['organization.update'],
        'teams.update' => ['organization.update'],
        'job-grades.update' => ['organization.update'],
        'job-levels.update' => ['organization.update'],
        'departments.update' => ['organization.update', 'assets.update'],
        'branches.delete' => ['organization.delete'],
        'teams.delete' => ['organization.delete'],
        'job-grades.delete' => ['organization.delete'],
        'job-levels.delete' => ['organization.delete'],
        'departments.delete' => ['organization.delete', 'assets.delete'],
    ];

    public function up(): void
    {
        $old = array_values(array_unique(array_merge(...array_values(self::GRANTS))));
        $holders = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.slug', $old)
            ->get(['permissions.slug', 'permission_role.role_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::GRANTS))->pluck('id', 'slug');
        $rows = [];
        foreach (self::GRANTS as $new => $from) {
            if (! isset($ids[$new])) {
                continue;
            }
            foreach ($from as $slug) {
                foreach ($holders[$slug] ?? [] as $roleId) {
                    $rows[$roleId.'-'.$ids[$new]] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
                }
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};
