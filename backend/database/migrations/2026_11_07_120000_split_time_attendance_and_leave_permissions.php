<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 3b: HRM > Time & Attendance and Leave lose their one "manage";
 * each tab gets its own Add / Update / Delete. A role holding the old manage
 * is given every new one of that menu; roles are read before sync() drops it.
 */
return new class extends Migration
{
    private const SPLITS = [
        'staff-attendance.manage' => [
            'check-ins.create', 'check-ins.update', 'check-ins.delete',
            'shifts.create', 'shifts.update', 'shifts.delete',
            'staff-shifts.create', 'staff-shifts.update', 'staff-shifts.delete',
            'holidays.create', 'holidays.update', 'holidays.delete',
            'overtime-requests.create', 'overtime-requests.delete',
            'attendance-corrections.create', 'attendance-corrections.delete',
        ],
        'leave-management.manage' => [
            'leave-types.create', 'leave-types.update', 'leave-types.delete',
            'leave-policies.create', 'leave-policies.update', 'leave-policies.delete',
            'leave-balances.create', 'leave-balances.delete',
            'leave-carry-forward.run',
            'leave-requests.create',
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
