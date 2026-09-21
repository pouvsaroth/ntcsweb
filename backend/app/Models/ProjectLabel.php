<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A label/tag Kanban cards can be tagged with (Backend, Frontend, Bug, ...) —
 * tenant-wide, shared across every project, same convention as BookCategory:
 * a small dedicated admin-managed table rather than the multilingual Base
 * Data lookup system, since a label needs no per-language translation.
 *
 * @property string $name
 */
#[Fillable(['name', 'color'])]
class ProjectLabel extends Model
{
    /** @use HasFactory<ProjectLabelFactory> */
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'project_task_label');
    }
}
