<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectTaskAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A file attached to a Kanban card — stored on the `public` disk, same convention as ExpenseAttachment. */
#[Fillable(['project_task_id', 'file_path', 'file_name', 'mime_type', 'uploaded_by'])]
class ProjectTaskAttachment extends Model
{
    /** @use HasFactory<ProjectTaskAttachmentFactory> */
    use HasFactory;

    protected $connection = 'tenant';

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
