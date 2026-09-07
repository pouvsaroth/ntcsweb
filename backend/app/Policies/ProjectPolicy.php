<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Any staff/admin can create and use projects — there is no per-project
 * membership gate, just the plain view/create/update/delete permissions
 * below. A column or task is only ever reached through its project (see
 * ProjectColumnController/ProjectTaskController), so it rides on this same
 * policy rather than needing one of its own: viewing a project's board
 * needs `view`, and creating/editing/reordering/deleting a column or task
 * needs `update` on the project it belongs to.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PROJECTS_VIEW);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermission(Permissions::PROJECTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PROJECTS_CREATE);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission(Permissions::PROJECTS_UPDATE);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermission(Permissions::PROJECTS_DELETE);
    }
}
