<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectTaskChecklistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One checklist/subtask line on a Kanban card. Deliberately not Auditable,
 * same reasoning as ProjectTaskComment: checking an item on/off is routine
 * card upkeep, not a business event worth a separate audit-trail entry.
 *
 * @property int $project_task_id
 * @property string $title
 * @property bool $is_completed
 * @property int $order
 */
#[Fillable(['project_task_id', 'title', 'is_completed', 'order'])]
class ProjectTaskChecklistItem extends Model
{
    /** @use HasFactory<ProjectTaskChecklistItemFactory> */
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $attributes = [
        'is_completed' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
