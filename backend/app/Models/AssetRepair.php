<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Assets\RepairStatus;
use Database\Factories\AssetRepairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repair job for one asset. `total_cost` is always server-computed by
 * AssetRepairService — see that class — never trusted from a request.
 * `expense_id` links to the Accounting Expense created (PENDING_APPROVAL,
 * never auto-paid) once the repair completes, following the existing
 * approval-gated Expense workflow rather than posting directly.
 */
#[Fillable([
    'repair_number', 'asset_id', 'issue_id', 'repair_shop_id', 'sent_date', 'expected_return_date', 'actual_return_date',
    'problem_description', 'diagnosis', 'repair_description', 'status',
    'diagnosis_cost', 'parts_cost', 'labor_cost', 'transport_cost', 'other_cost', 'total_cost',
    'warranty_days', 'condition_after_repair',
    'decision', 'decision_by', 'decision_date', 'decision_reason',
    'expense_id', 'notes', 'created_by',
])]
class AssetRepair extends Model
{
    /** @use HasFactory<AssetRepairFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => RepairStatus::PENDING,
        'diagnosis_cost' => 0,
        'parts_cost' => 0,
        'labor_cost' => 0,
        'transport_cost' => 0,
        'other_cost' => 0,
        'total_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sent_date' => 'date',
            'expected_return_date' => 'date',
            'actual_return_date' => 'date',
            'diagnosis_cost' => 'decimal:2',
            'parts_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'transport_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'decision_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(AssetIssue::class, 'issue_id');
    }

    public function repairShop(): BelongsTo
    {
        return $this->belongsTo(RepairShop::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return RepairStatus::isClosed($this->status);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [RepairStatus::RETURNED, RepairStatus::CANCELLED]);
    }

    public function auditDisplayName(): string
    {
        return $this->repair_number;
    }
}
