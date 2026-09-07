<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProjectTaskComment;
use App\Models\User;

/**
 * Rides on the parent project's own permission (see ProjectPolicy's
 * docblock) rather than a dedicated permission slug: anyone who can update
 * the project can comment on its cards — that check is made directly
 * against the parent ProjectTask in StoreProjectTaskCommentRequest, since a
 * comment being created doesn't exist yet to hand this policy. Deleting an
 * existing one is additionally allowed for the comment's own author,
 * regardless of permission — removing your own remark shouldn't require an
 * elevated role.
 */
class ProjectTaskCommentPolicy
{
    public function delete(User $user, ProjectTaskComment $comment): bool
    {
        return $comment->user_id === $user->getKey() || $user->can('update', $comment->task->project);
    }
}
