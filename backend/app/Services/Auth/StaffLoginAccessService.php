<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Staff;
use App\Models\User;

/**
 * A staff member's login follows their HR status: only the statuses in
 * CAN_LOG_IN (still working here) may sign in. Any other status (resigned,
 * terminated, retired, suspended) switches the account to
 * User::STATUS_INACTIVE; changing it back to one of CAN_LOG_IN lifts that.
 *
 * Only ever toggles between ACTIVE and INACTIVE — an account that is
 * INVITED (no password yet), SUSPENDED or PENDING_APPROVAL is someone
 * else's decision and is left exactly as it is.
 */
final class StaffLoginAccessService
{
    public const CAN_LOG_IN = [Staff::STATUS_ACTIVE, Staff::STATUS_PROBATION, Staff::STATUS_ON_LEAVE];

    /** Needs the tenant connection (reads the staff row's user). */
    public function sync(Staff $staff): void
    {
        $user = $staff->user()->first();

        if ($user === null) {
            return;
        }

        if (in_array($staff->status, self::CAN_LOG_IN, true)) {
            // A studying_ended_at means the *student* rule locked it (see
            // StudentAccessService) — that one lifts itself, not via HR.
            if ($user->status === User::STATUS_INACTIVE && $user->studying_ended_at === null) {
                $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
            }

            return;
        }

        if ($user->status === User::STATUS_ACTIVE) {
            $user->forceFill(['status' => User::STATUS_INACTIVE])->save();
        }
    }
}
