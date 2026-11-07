<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 2 (Academic): School Setup, Assessment Setup, Exam Schedule and
 * Timetable get Add / Update / Delete (Timetable also Approve / Reject for
 * schedule change requests) instead of one "manage" permission. Every role
 * holding an old manage slug is given each new one made from it; the roles
 * are read before sync() drops the retired slugs.
 */
return new class extends Migration
{
    /** old "manage" slug => the new slugs that replace it */
    private const SPLIT = [
        'academic-terms.manage' => ['academic-terms.create', 'academic-terms.update', 'academic-terms.delete'],
        'grade-levels.manage' => ['grade-levels.create', 'grade-levels.update', 'grade-levels.delete'],
        'curriculum.manage' => ['curriculum.create', 'curriculum.update', 'curriculum.delete'],
        'school-calendar.manage' => ['school-calendar.create', 'school-calendar.update', 'school-calendar.delete'],
        'promotion-rules.manage' => ['promotion-rules.create', 'promotion-rules.update', 'promotion-rules.delete'],
        'assessment.manage' => ['assessment.create', 'assessment.update', 'assessment.delete'],
        'exam-schedules.manage' => ['exam-schedules.create', 'exam-schedules.update', 'exam-schedules.delete'],
        'timetable.manage' => ['timetable.create', 'timetable.update', 'timetable.delete', 'timetable.approve', 'timetable.reject'],
    ];

    public function up(): void
    {
        $holders = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.slug', array_keys(self::SPLIT))
            ->get(['permissions.slug', 'permission_role.role_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_merge(...array_values(self::SPLIT)))->pluck('id', 'slug');

        $rows = [];
        foreach (self::SPLIT as $old => $news) {
            foreach ($holders->get($old, []) as $roleId) {
                foreach ($news as $new) {
                    if (isset($ids[$new])) {
                        $rows[$roleId.':'.$ids[$new]] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
                    }
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
