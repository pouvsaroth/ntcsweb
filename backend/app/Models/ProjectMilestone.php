<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProjectMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Sprint/Milestone on a project's Kanban board — see the migration's
 * docblock. Independent of ProjectColumn/status: a card's milestone doesn't
 * change when it's dragged between columns.
 *
 * @property int $project_id
 * @property string $name
 * @property int $order
 */
#[Fillable(['project_id', 'name', 'start_date', 'due_date', 'order'])]
class ProjectMilestone extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ProjectMilestoneFactory> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function auditModule(): string
    {
        return 'Projects';
    }
}
