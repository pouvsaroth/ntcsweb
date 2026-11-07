<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 3a: HRM > Payroll's one "manage" becomes Add / Update / Delete
 * per tab (payroll-<tab>.<action>). Every role holding payroll.manage is
 * given all of them; the roles are read before sync() drops payroll.manage.
 */
return new class extends Migration
{
    private const NEW = [
        'payroll-salary-structures.create', 'payroll-salary-structures.update', 'payroll-salary-structures.delete',
        'payroll-basic-salary.create', 'payroll-basic-salary.update', 'payroll-basic-salary.delete',
        'payroll-allowances.create', 'payroll-allowances.update', 'payroll-allowances.delete',
        'payroll-bonuses.create', 'payroll-bonuses.update', 'payroll-bonuses.delete',
        'payroll-deductions.create', 'payroll-deductions.update', 'payroll-deductions.delete',
        'payroll-overtime.update',
        'payroll-attendance-deduction.update',
        'payroll-tax.update',
        'payroll-social-security.create', 'payroll-social-security.update', 'payroll-social-security.delete',
        'payroll-loans.create', 'payroll-loans.update', 'payroll-loans.delete',
    ];

    public function up(): void
    {
        $roleIds = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.slug', 'payroll.manage')
            ->pluck('permission_role.role_id');

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', self::NEW)->pluck('id');
        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($ids as $id) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $id];
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
