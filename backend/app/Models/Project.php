<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Kanban-style project — see the migration's docblock. Any staff/admin can
 * create and use one (see ProjectPolicy's docblock); there is no
 * per-project membership gate.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'description', 'status', 'created_by'])]
class Project extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<ProjectFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * Soft-deleting a project cascades to its columns and tasks — a column/
     * task is only ever reached through its (non-deleted) project, so
     * leaving them as live rows after their project is gone would just be
     * unreachable clutter. Deletes one at a time (not a bulk query delete)
     * so each row still fires its own model events — Auditable logs each
     * one, same as if it had been deleted directly.
     */
    protected static function booted(): void
    {
        static::deleting(function (Project $project) {
            $project->tasks()->get()->each->delete();
            $project->columns()->get()->each->delete();
        });
    }

    public function columns(): HasMany
    {
        return $this->hasMany(ProjectColumn::class)->orderBy('order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditModule(): string
    {
        return 'Projects';
    }
}
