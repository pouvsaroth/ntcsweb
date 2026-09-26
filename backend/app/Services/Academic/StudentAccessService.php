<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;

/**
 * A student's login follows whether they're still studying: once their last
 * Studying (Enrollment::STATUS_ACTIVE) enrollment changes to any other
 * status, a countdown starts; after the school's grace period
 * (Tenant::studentInactiveAfterDays()) their account goes
 * User::STATUS_INACTIVE and can't sign in. An enrollment becoming Studying
 * again lifts it automatically.
 *
 * The countdown lives on the central users row (see the
 * add_studying_ended_at_to_users_table migration), so the expiry itself can
 * be decided without the school's own database: at login/per request
 * (expireIfDue()) and by the daily students:deactivate-idle command.
 */
final class StudentAccessService
{
    /**
     * The statuses a student can have *left* Studying into — a student whose
     * enrollments are only ever Not Started (or Dropped by a transfer/cancel
     * that replaced them) never studied yet, so no countdown starts for them.
     */
    private const LEFT_STUDYING_STATUSES = [
        Enrollment::STATUS_EXAM_READY, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_ABANDONED,
        Enrollment::STATUS_STOPPED, Enrollment::STATUS_SUSPENDED,
    ];

    /** Re-derives the countdown for one student from their enrollments. Needs the tenant connection. */
    public function sync(Student $student): void
    {
        $user = $student->user;

        if ($user === null) {
            return;
        }

        $enrollments = $student->enrollments();

        if ((clone $enrollments)->where('status', Enrollment::STATUS_ACTIVE)->exists()) {
            $this->markStudying($user);
        } elseif ((clone $enrollments)->whereIn('status', self::LEFT_STUDYING_STATUSES)->exists()) {
            $this->markLeftStudying($user);
        }
    }

    /** True once the grace period is over and the rule hasn't already been applied (or overridden by an admin). */
    public function isDue(User $user, Tenant $tenant): bool
    {
        return $user->status === User::STATUS_ACTIVE
            && $user->studying_ended_at !== null
            && $user->auto_deactivated_at === null
            && $user->studying_ended_at->lte(now()->subDays($tenant->studentInactiveAfterDays()));
    }

    /** Applies the rule to one user if it's due. Returns whether it did. Central data only. */
    public function expireIfDue(User $user): bool
    {
        // Cheap column check first — this runs on every authenticated
        // request (EnsureUserIsActive), and almost nobody has a countdown.
        if ($user->studying_ended_at === null) {
            return false;
        }

        $tenant = $user->tenant;

        if ($tenant === null || ! $this->isDue($user, $tenant)) {
            return false;
        }

        $user->forceFill(['status' => User::STATUS_INACTIVE, 'auto_deactivated_at' => now()])->save();

        return true;
    }

    /** @return int how many accounts were switched off */
    public function expireDueForTenant(Tenant $tenant): int
    {
        $count = 0;

        User::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('auto_deactivated_at')
            ->where('studying_ended_at', '<=', now()->subDays($tenant->studentInactiveAfterDays()))
            ->each(function (User $user) use (&$count) {
                if ($this->expireIfDue($user)) {
                    $count++;
                }
            });

        return $count;
    }

    private function markStudying(User $user): void
    {
        $changes = ['studying_ended_at' => null, 'auto_deactivated_at' => null];

        // Only the automatic state is lifted — a suspension an admin chose
        // stays exactly as they left it.
        if ($user->status === User::STATUS_INACTIVE) {
            $changes['status'] = User::STATUS_ACTIVE;
        }

        $user->forceFill($changes);

        if ($user->isDirty()) {
            $user->save();
        }
    }

    private function markLeftStudying(User $user): void
    {
        if ($user->studying_ended_at === null) {
            $user->forceFill(['studying_ended_at' => now()])->save();
        }
    }
}
