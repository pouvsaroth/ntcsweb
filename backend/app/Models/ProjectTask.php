<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\AuditAction;
use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One card on a project's Kanban board — see the migration's docblock and
 * ProjectTaskService::move() for how drag-and-drop repositioning works.
 *
 * @property int $tenant_id
 * @property int $project_id
 * @property int $project_column_id
 * @property string $title
 * @property string $priority
 * @property int $order
 */
#[Fillable(['project_id', 'project_column_id', 'title', 'description', 'priority', 'due_date', 'assignee_id', 'order', 'created_by'])]
class ProjectTask extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

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
            'due_date' => 'date',
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
