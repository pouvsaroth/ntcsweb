<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A room a class can be scheduled into.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'code', 'capacity', 'location', 'building_id', 'status'])]
class Classroom extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<ClassroomFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(ClassroomTable::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
