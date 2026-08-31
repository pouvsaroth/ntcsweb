<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title that carries a Role. Staff creation reads `Position::$role` to
 * decide what the auto-provisioned User is allowed to do — see
 * StaffController::store() — so the role is never re-decided per Staff
 * member, only per Position.
 *
 * @property int $tenant_id
 * @property int $role_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'role_id', 'description', 'status'])]
class Position extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<PositionFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function auditModule(): string
    {
        return 'Positions';
    }
}
