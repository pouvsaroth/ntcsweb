<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Performance Management's set-up (KPIs, goals, evaluation forms,
 * review cycles, score weights): view to read, manage to change.
 */
class PerformancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_MANAGE);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_MANAGE);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_MANAGE);
    }
}
