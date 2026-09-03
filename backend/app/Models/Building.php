<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A physical building on campus that classrooms belong to.
 *
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'code', 'address', 'status'])]
class Building extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<BuildingFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * PHP-level mirror of the column's DB default. Without this, a freshly
     * created() model reflects `status: null` in the same response that
     * created it — Eloquent's insert only sends columns actually set on the
     * model, so the DB's own `default('active')` is invisible to that
     * in-memory instance until it's re-fetched. Setting it here means the
     * value is correct from the moment the model exists, both paths agree,
     * and it satisfies Model::shouldBeStrict() for a plain `new Building`.
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
