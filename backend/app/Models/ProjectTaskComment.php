<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectTaskCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a Kanban card — see the migration's docblock. Deliberately
 * not Auditable: a comment is already its own visible record, and giving it
 * an audit trail too would just duplicate it in a second place.
 *
 * @property int $project_task_id
 * @property int $user_id
 * @property string $body
 */
#[Fillable(['project_task_id', 'user_id', 'body'])]
class ProjectTaskComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ProjectTaskCommentFactory> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
