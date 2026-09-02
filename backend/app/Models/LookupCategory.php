<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LookupCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned, configurable category of dropdown values (GENDER,
 * GUARDIAN_TYPE, BOOK_TYPE, PAYMENT_METHOD, ...) -- see the migration's
 * docblock. `code` is the stable identifier application logic checks
 * against; `name`/`description` are admin-facing display text only.
 */
#[Fillable(['code', 'name', 'description', 'is_active', 'sort_order'])]
class LookupCategory extends Model
{
    /** @use HasFactory<LookupCategoryFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(LookupValue::class);
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
        return 'Base Data';
    }

    public function auditDisplayName(): string
    {
        return "{$this->code} - {$this->name}";
    }
}
