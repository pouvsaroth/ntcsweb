<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssetStatus;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * Tenant-owned. One physical item the school owns and tracks through its
 * whole working life — see the migration's docblock for the schema
 * reasoning (flexible `specs` jsonb for category-specific fields like CPU/
 * RAM/OS, three promoted searchable columns for computers, disposal fields
 * inline rather than a separate table).
 *
 * Does NOT use the Auditable trait — AssetService/AssetLifecycleService/
 * AssetRepairService fire their own explicit, richly-described audit
 * entries (see AuditAction's Assets section), the same reasoning as
 * Invoice/Payment/Expense: a lifecycle event needs a narrative a generic
 * column-diff can't produce. See AssetHistory for the separate, non-audit
 * "what happened to this asset" business record.
 *
 * @property int $tenant_id
 * @property string $asset_number
 * @property string $status
 * @property string $condition
 */
#[Fillable([
    'asset_number', 'category_id', 'name', 'description', 'brand', 'model', 'serial_number', 'asset_tag',
    'purchase_date', 'purchase_price', 'current_value', 'supplier_id',
    'warranty_start_date', 'warranty_end_date', 'warranty_provider', 'warranty_number',
    'location_id', 'department_id', 'status', 'condition',
    'hostname', 'mac_address', 'ip_address', 'specs',
    'disposal_date', 'disposal_reason', 'disposal_method', 'disposal_value', 'disposal_approved_by', 'disposed_by',
    'notes', 'created_by',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => AssetStatus::IN_STOCK,
        'condition' => AssetCondition::NEW,
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
            'current_value' => 'decimal:2',
            'warranty_start_date' => 'date',
            'warranty_end_date' => 'date',
            'disposal_date' => 'date',
            'disposal_value' => 'decimal:2',
            'specs' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /** The single open assignment, if any — see AssetAssignment's own docblock for why there is at most one at a time. */
    public function currentAssignment(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->where('status', 'ACTIVE');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AssetIssue::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(AssetRepair::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(AssetHistory::class)->orderByDesc('occurred_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function disposalApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposal_approved_by');
    }

    /** Reads a category-specific field out of `specs` — mirrors Tenant::setting()'s exact dot-notation convention. */
    public function spec(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->specs ?? [], $key, $default);
    }

    public function isClosed(): bool
    {
        return AssetStatus::isClosed($this->status);
    }

    public function warrantyIsActive(): bool
    {
        return $this->warranty_end_date !== null && $this->warranty_end_date->isFuture();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWarrantyExpiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('warranty_end_date')
            ->whereBetween('warranty_end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function auditDisplayName(): string
    {
        return "{$this->asset_number} - {$this->name}";
    }
}
