<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LeaveRequestAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A file attached to a LeaveRequest — photo or other document — stored on the `public` disk, same convention as ExpenseAttachment/AssetDocument. */
#[Fillable(['leave_request_id', 'file_path', 'file_name', 'mime_type'])]
class LeaveRequestAttachment extends Model
{
    /** @use HasFactory<LeaveRequestAttachmentFactory> */
    use BelongsToTenant, HasFactory;

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
