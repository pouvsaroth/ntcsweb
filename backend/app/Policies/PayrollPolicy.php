<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollComponent;
use App\Models\SalaryStructure;
use App\Models\SocialSecurityScheme;
use App\Models\StaffLoan;
use App\Models\StaffPayComponent;
use App\Models\StaffSalary;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Payroll's set-up: payroll.view to read; adding, changing and
 * deleting is per tab — `payroll-<tab>.create|update|delete` (e.g.
 * payroll-allowances.create). A record's tab comes from its kind (see tab());
 * a create names its tab: authorize('create', [Model::class, 'loans']).
 * The settings tabs (Overtime, Attendance deduction, Tax) are checked field
 * by field in PayrollRulesController / PayrollStaffController.
 */
class PayrollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_VIEW);
    }

    public function create(User $user, ?string $tab = null): bool
    {
        return $tab !== null && $user->hasPermission("payroll-{$tab}.create");
    }

    public function update(User $user, Model $record): bool
    {
        $tab = self::tab($record);

        return $tab !== null && $user->hasPermission("payroll-{$tab}.update");
    }

    public function delete(User $user, Model $record): bool
    {
        $tab = self::tab($record);

        return $tab !== null && $user->hasPermission("payroll-{$tab}.delete");
    }

    /** The Payroll tab a record lives on (null: not a per-tab record). */
    public static function tab(Model $record): ?string
    {
        return match (true) {
            $record instanceof SalaryStructure => 'salary-structures',
            $record instanceof StaffSalary => 'basic-salary',
            $record instanceof PayrollComponent => self::kindTab($record->kind),
            $record instanceof StaffPayComponent => self::kindTab($record->component?->kind),
            $record instanceof SocialSecurityScheme => 'social-security',
            $record instanceof StaffLoan => 'loans',
            default => null,
        };
    }

    /** allowance -> allowances, bonus -> bonuses, deduction -> deductions. */
    public static function kindTab(?string $kind): ?string
    {
        return match ($kind) {
            PayrollComponent::KIND_ALLOWANCE => 'allowances',
            PayrollComponent::KIND_BONUS => 'bonuses',
            PayrollComponent::KIND_DEDUCTION => 'deductions',
            default => null,
        };
    }
}
