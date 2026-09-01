<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One custody record — never updated to point at a new holder. Assigning to
 * someone else closes this row (`status` = RETURNED, `returned_date` set)
 * and a fresh row is inserted, so the complete assignment history survives;
 * see the migration's docblock and AssetService::assign()/return().
 *
 * @property int $tenant_id
 * @property int $asset_id
 * @property string $status
 */
#[Fillable([
    'asset_id', 'assignable_type', 'assignable_id', 'assigned_by', 'assigned_date',
    'expected_return_date', 'returned_date', 'condition_at_assignment', 'condition_at_return',
    'status', 'notes',
])]
class AssetAssignment extends Model
{
    /** @use HasFactory<AssetAssignmentFactory> */
    use BelongsToTenant, HasFactory;

    public const ACTIVE = 'ACTIVE';

    public const RETURNED = 'RETURNED';

    protected $attributes = [
        'status' => self::ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'expected_return_date' => 'date',
            'returned_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }
}
