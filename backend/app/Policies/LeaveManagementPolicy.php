<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Leave Management's set-up and records (leave types, policies,
 * balances): view to read, manage to change. Deciding a leave request stays
 * with LeaveRequestPolicy / the Approvals queue.
 */
class LeaveManagementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::LEAVE_MANAGEMENT_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::LEAVE_MANAGEMENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::LEAVE_MANAGEMENT_MANAGE);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::LEAVE_MANAGEMENT_MANAGE);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::LEAVE_MANAGEMENT_MANAGE);
    }
}
