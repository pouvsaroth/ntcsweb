<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Tracks one CSV upload's background processing — see
 * App\Jobs\ProcessStudentImport for where `status`/counts/`errors` are
 * actually filled in.
 *
 * @property int $tenant_id
 * @property string $status
 * @property array|null $errors
 */
#[Fillable([
    'user_id', 'original_filename', 'file_path', 'status',
    'total_rows', 'imported_count', 'skipped_count', 'errors',
    'started_at', 'completed_at',
])]
class StudentImport extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
