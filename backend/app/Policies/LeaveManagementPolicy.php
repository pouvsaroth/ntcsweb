<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveBalanceEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Leave Management's set-up and records: leave-management.view to read;
 * Add / Update / Delete per tab — Leave types (leave-types.*), Leave policies
 * (leave-policies.*), Leave balance (leave-balances.create / .delete). Carry
 * forward is leave-carry-forward.run (LeaveBalanceController). Deciding a
 * leave request stays with LeaveRequestPolicy / the Approvals queue.
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

    /** $class names the tab: authorize('create', [LeaveType::class, LeaveType::class]). */
    public function create(User $user, ?string $class = null): bool
    {
        $tab = self::tab($class);

        return $tab !== null && $user->hasPermission("{$tab}.create");
    }

    public function update(User $user, Model $record): bool
    {
        $tab = self::tab($record::class);

        return $tab !== null && $user->hasPermission("{$tab}.update");
    }

    public function delete(User $user, Model $record): bool
    {
        $tab = self::tab($record::class);

        return $tab !== null && $user->hasPermission("{$tab}.delete");
    }

    private static function tab(?string $class): ?string
    {
        return match ($class) {
            LeaveType::class => 'leave-types',
            LeavePolicy::class => 'leave-policies',
            LeaveBalanceEntry::class => 'leave-balances',
            default => null,
        };
    }
}
