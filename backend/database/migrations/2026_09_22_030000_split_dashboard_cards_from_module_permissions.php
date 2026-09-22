<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the nine new `dashboard.cards.*` permissions (see
 * Permissions::DASHBOARD_CARDS_REGISTER_STUDENT etc.) onto every role —
 * system or custom, any tenant — that currently holds the underlying CRUD
 * permission each dashboard tile used to be gated by directly (e.g. the
 * Classes card required classes.view; see the old Dashboard.vue). Whoever
 * already effectively saw a tile keeps seeing it after this ships; a school
 * admin can now uncheck a card from a role in the Role editor without also
 * revoking that role's real access to the underlying page. Only additive.
 */
return new class extends Migration
{
    private const MAP = [
        'dashboard.cards.register-student' => 'students.create',
        'dashboard.cards.enrollment' => 'enrollments.create',
        'dashboard.cards.classes' => 'classes.view',
        'dashboard.cards.student-payment' => 'payments.view',
        'dashboard.cards.student-attendance' => 'attendance.view',
        'dashboard.cards.teacher-attendance' => 'staff.view',
        'dashboard.cards.registration-pending' => 'students.approve-registration',
        'dashboard.cards.users' => 'users.view',
        'dashboard.cards.roles' => 'roles.view',
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');

        $rows = [];

        foreach (self::MAP as $cardSlug => $sourceSlug) {
            $cardId = $permissionIds[$cardSlug] ?? null;
            $sourceId = $permissionIds[$sourceSlug] ?? null;

            if ($cardId === null || $sourceId === null) {
                continue;
            }

            $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $cardId];
            }
        }

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $cardIds = DB::table('permissions')->whereIn('slug', array_keys(self::MAP))->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $cardIds)->delete();
    }
};
