<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProjectColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One lane on a project's Kanban board — see the migration's docblock.
 *
 * @property int $project_id
 * @property string $name
 * @property int $order
 */
#[Fillable(['project_id', 'name', 'color', 'order'])]
class ProjectColumn extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<ProjectColumnFactory> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('order');
    }

    public function auditModule(): string
    {
        return 'Projects';
    }
}
