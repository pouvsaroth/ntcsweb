<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\TenantContext;
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
 * @property int $role_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'role_id', 'description', 'status'])]
class Position extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

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

    /**
     * Which of this tenant's positions carry the "Teacher" role — i.e. are
     * eligible for a class's teachers/assistant teachers and the
     * class-assignment picker's real eligibility test. Not "named exactly
     * 'Teacher'": a school's own
     * position titles are free text (often translated, e.g. "គ្រូបង្រៀន
     * កុំព្យូទ័រ") and there can be more than one (a "Computer Teacher" and a
     * "Teaching Assistant" might both carry the Teacher role). `roles` lives
     * on the central connection while `positions` is per-tenant, so this is
     * necessarily two queries rather than one join.
     *
     * @return list<int>
     */
    public static function teacherPositionIds(): array
    {
        $teacherRoleId = Role::query()
            ->where('tenant_id', app(TenantContext::class)->idOrFail())
            ->where('slug', Role::TEACHER)
            ->value('id');

        if ($teacherRoleId === null) {
            return [];
        }

        return static::query()->where('role_id', $teacherRoleId)->pluck('id')->all();
    }

    public function auditModule(): string
    {
        return 'Positions';
    }
}
