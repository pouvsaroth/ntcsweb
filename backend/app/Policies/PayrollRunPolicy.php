<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * HRM > Payroll's runs: payroll.view to see them (and payslips), payroll.run
 * to work one out, send it for approval and pay it, payroll.approve to
 * approve or reject it when the school has no Approval Flow for payroll
 * (with one, the current step's group decides — see ApprovalFlow).
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_VIEW) || $user->hasPermission(Permissions::PAYROLL_APPROVE);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_RUN);
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_RUN);
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_APPROVE);
    }

    public function reject(User $user, PayrollRun $run): bool
    {
        return $user->hasPermission(Permissions::PAYROLL_APPROVE);
    }
}
