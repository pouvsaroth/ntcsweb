<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch, Team, JobGrade and JobLevel — HRM > Organization Management's
 * lists: organization.view to read; each list has its own Add / Update /
 * Delete (branches.create, teams.update, ...). A create names its model:
 * authorize('create', [Branch::class, Branch::class]).
 */
class OrganizationUnitPolicy
{
    /** Model => permission prefix. */
    private const LISTS = [
        Branch::class => 'branches',
        Team::class => 'teams',
        JobGrade::class => 'job-grades',
        JobLevel::class => 'job-levels',
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_VIEW);
    }

    public function view(User $user, Model $unit): bool
    {
        return $user->hasPermission(Permissions::ORGANIZATION_VIEW);
    }

    public function create(User $user, ?string $class = null): bool
    {
        return $this->allows($user, $class, 'create');
    }

    public function update(User $user, Model $unit): bool
    {
        return $this->allows($user, $unit::class, 'update');
    }

    public function delete(User $user, Model $unit): bool
    {
        return $this->allows($user, $unit::class, 'delete');
    }

    private function allows(User $user, ?string $class, string $action): bool
    {
        $list = $class !== null ? (self::LISTS[$class] ?? null) : null;

        return $list !== null && $user->hasPermission("{$list}.{$action}");
    }
}
