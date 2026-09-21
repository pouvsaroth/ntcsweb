<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One card on a project's Kanban board — see the migration's docblock and
 * ProjectTaskService::move() for how drag-and-drop repositioning works.
 *
 * @property int $project_id
 * @property int $project_column_id
 * @property string $title
 * @property string $priority
 * @property int $order
 */
#[Fillable([
    'project_id', 'project_column_id', 'title', 'description', 'priority',
    'start_date', 'due_date', 'estimated_hours', 'project_milestone_id',
    'assignee_id', 'order', 'created_by',
])]
class ProjectTask extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ProjectTaskFactory> */
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    protected $attributes = [
        'priority' => self::PRIORITY_MEDIUM,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'estimated_hours' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(ProjectColumn::class, 'project_column_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectTaskComment::class)->latest();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ProjectLabel::class, 'project_task_label');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ProjectTaskChecklistItem::class)->orderBy('order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectTaskAttachment::class);
    }

    /** Cards this one depends on (its blockers). */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'project_task_dependencies',
            'project_task_id',
            'depends_on_project_task_id',
        );
    }

    /** Cards that depend on this one. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'project_task_dependencies',
            'depends_on_project_task_id',
            'project_task_id',
        );
    }

    public function auditModule(): string
    {
        return 'Projects';
    }

    public function auditDisplayName(): string
    {
        return $this->title;
    }

    /**
     * `order` changes constantly as a side effect of drag-and-drop
     * reindexing (see ProjectTaskService::move()) — every sibling in the
     * affected column gets its `order` rewritten on every single drop. None
     * of that is a meaningful business event on its own, so it's excluded
     * here rather than flooding the audit trail; a real column move is still
     * captured via `project_column_id`; see auditActionForDirty().
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['order'];
    }

    /**
     * @return array<string, callable(mixed): (string|null)>
     */
    protected function auditLabels(): array
    {
        return [
            'project_column_id' => fn ($value) => ProjectColumn::find($value)?->name,
        ];
    }

    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('project_column_id', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            $from = $old['project_column_label'] ?? 'another column';
            $to = $new['project_column_label'] ?? 'another column';

            return "Moved \"{$this->title}\" from {$from} to {$to}";
        }

        return null;
    }
}
