<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A configurable, hierarchical physical location (Main Campus
 * > Administration Building > Computer Lab 1) — see the migration's
 * docblock for why this is separate from the flat `classrooms` table, and
 * how `classroom_id` optionally links the two.
 */
#[Fillable(['code', 'name', 'type', 'parent_id', 'classroom_id', 'is_active'])]
class AssetLocation extends Model
{
    /** @use HasFactory<AssetLocationFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    public const CAMPUS = 'CAMPUS';

    public const BUILDING = 'BUILDING';

    public const FLOOR = 'FLOOR';

    public const ROOM = 'ROOM';

    public const OTHER = 'OTHER';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::CAMPUS, self::BUILDING, self::FLOOR, self::ROOM, self::OTHER];
    }

    protected $attributes = [
        'type' => self::ROOM,
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

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'location_id');
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
