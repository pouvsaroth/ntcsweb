<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Payroll's set-up (components, salary structures, staff salaries and
 * their allowances/bonuses/deductions): view to read, manage to change.
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

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_MANAGE);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_MANAGE);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_MANAGE);
    }
}
