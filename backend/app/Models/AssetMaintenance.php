<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Assets\MaintenanceStatus;
use Database\Factories\AssetMaintenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled or completed maintenance task. `next_maintenance_date` is
 * computed and stored once, at completion, from `recurrence_interval_months`
 * — read passively, no background job. `OVERDUE` is never stored; see
 * MaintenanceStatus's own docblock and isOverdue() below.
 */
#[Fillable([
    'maintenance_number', 'asset_id', 'maintenance_type', 'scheduled_date', 'completed_date', 'description',
    'repair_shop_id', 'cost', 'status', 'recurrence_interval_months', 'next_maintenance_date', 'notes', 'created_by',
])]
class AssetMaintenance extends Model
{
    /** @use HasFactory<AssetMaintenanceFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => MaintenanceStatus::SCHEDULED,
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_date' => 'date',
            'cost' => 'decimal:2',
            'next_maintenance_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function repairShop(): BelongsTo
    {
        return $this->belongsTo(RepairShop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === MaintenanceStatus::SCHEDULED && $this->scheduled_date->isPast();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('status', MaintenanceStatus::SCHEDULED)->orderBy('scheduled_date');
    }

    public function auditDisplayName(): string
    {
        return $this->maintenance_number;
    }
}
