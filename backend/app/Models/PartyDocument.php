<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Political Party > Documents: one private file (on the
 * `local` disk), in a folder or at the top. `name` is what people see and
 * download it as, extension included.
 */
#[Fillable(['folder_id', 'name', 'file_path', 'mime', 'size', 'uploaded_by'])]
class PartyDocument extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(PartyDocumentFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function auditModule(): string
    {
        return 'Political Party';
    }
}
