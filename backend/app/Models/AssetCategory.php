<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A configurable, hierarchical asset category (IT Equipment >
 * Computer/Laptop/Monitor) — see the migration's docblock. Uses the generic
 * Auditable trait, same reasoning as Account: a simple config record whose
 * edits a plain column-diff describes well.
 */
#[Fillable(['code', 'name', 'description', 'parent_id', 'is_active'])]
class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function auditModule(): string
    {
        return 'Assets';
    }

    public function auditDisplayName(): string
    {
        return "{$this->code} - {$this->name}";
    }
}
