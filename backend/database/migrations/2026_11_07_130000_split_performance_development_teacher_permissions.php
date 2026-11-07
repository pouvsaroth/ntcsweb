<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 3c: HRM > Performance, Professional Development and Teachers
 * lose their one "manage"; each tab gets its own Add / Update / Delete. A
 * role holding the old manage is given every new one of that menu; roles are
 * read before sync() drops it.
 */
return new class extends Migration
{
    private const SPLITS = [
        'performance.manage' => [
            'performance-kpis.create',
            'performance-kpis.update',
            'performance-kpis.delete',
            'performance-goals.create',
            'performance-goals.update',
            'performance-goals.delete',
            'performance-reviews.create',
            'performance-reviews.update',
            'performance-reviews.delete',
            'performance-forms.create',
            'performance-forms.update',
            'performance-forms.delete',
            'performance-promotions.create',
            'performance-promotions.update',
        ],
        'professional-development.manage' => [
            'trainings.create',
            'trainings.update',
            'trainings.delete',
            'training-certificates.create',
            'training-certificates.delete',
            'classroom-observations.create',
            'classroom-observations.update',
            'classroom-observations.delete',
            'coaching.create',
            'coaching.update',
            'coaching.delete',
            'development-plans.create',
            'development-plans.update',
            'development-plans.delete',
        ],
        'teachers.manage' => [
            'teacher-qualifications.create',
            'teacher-qualifications.update',
            'teacher-qualifications.delete',
            'teacher-assignments.create',
            'teacher-assignments.update',
            'teacher-assignments.delete',
            'teacher-homeroom.update',
            'teacher-availability.create',
            'teacher-availability.update',
            'teacher-availability.delete',
            'teacher-substitutes.create',
            'teacher-substitutes.delete',
            'teacher-documents.create',
            'teacher-documents.delete',
        ],
    ];
    public function up(): void
    {
        $holders = [];
        foreach (array_keys(self::SPLITS) as $old) {
            $holders[$old] = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.slug', $old)
                ->pluck('permission_role.role_id');
        }

        app(PermissionRegistry::class)->sync();

        $rows = [];
        foreach (self::SPLITS as $old => $new) {
            $ids = DB::table('permissions')->whereIn('slug', $new)->pluck('id');
            foreach ($holders[$old] as $roleId) {
                foreach ($ids as $id) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $id];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};
