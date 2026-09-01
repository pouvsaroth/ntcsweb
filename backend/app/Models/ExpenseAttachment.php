<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ExpenseAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A receipt/invoice attached to an Expense — stored on the `public` disk, same convention as Gallery/avatars. */
#[Fillable(['expense_id', 'file_path', 'file_name', 'mime_type', 'uploaded_by'])]
class ExpenseAttachment extends Model
{
    /** @use HasFactory<ExpenseAttachmentFactory> */
    use BelongsToTenant, HasFactory;

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
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
