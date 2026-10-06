<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Recruitment's records other than the manpower request (which has its
 * own E-Approvals rules, see ManpowerRequestPolicy) — job positions, job
 * postings, and the later tabs — all on the one recruitment.* set.
 */
class RecruitmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_CREATE);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_UPDATE);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::RECRUITMENT_DELETE);
    }
}
