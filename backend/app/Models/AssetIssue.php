<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Assets\IssuePriority;
use App\Support\Assets\IssueStatus;
use Database\Factories\AssetIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reported problem with an asset — the trigger for an AssetRepair; see AssetIssueService. */
#[Fillable([
    'issue_number', 'asset_id', 'reported_by', 'reported_date', 'priority', 'status',
    'title', 'description', 'resolved_at', 'resolved_by',
])]
class AssetIssue extends Model
{
    /** @use HasFactory<AssetIssueFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'priority' => IssuePriority::MEDIUM,
        'status' => IssueStatus::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'reported_date' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(AssetRepair::class, 'issue_id');
    }

    public function isClosed(): bool
    {
        return IssueStatus::isClosed($this->status);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [IssueStatus::RESOLVED, IssueStatus::CLOSED, IssueStatus::CANCELLED]);
    }

    public function auditDisplayName(): string
    {
        return $this->issue_number;
    }
}
