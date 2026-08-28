<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. A teacher's profile is independent of a login account —
 * `user_id` is nullable, since a school may record a teacher without ever
 * issuing them portal access.
 *
 * @property int $tenant_id
 * @property string $employee_code
 * @property string $name
 * @property string $status
 */
#[Fillable(['user_id', 'employee_code', 'name', 'email', 'phone', 'specialization', 'bio', 'hire_date', 'status'])]
class Teacher extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<TeacherFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * PHP-level mirror of the column's DB default. Without this, a freshly
     * created() model reflects `status: null` in the same response that
     * created it — Eloquent's insert only sends columns actually set on the
     * model, so the DB's own `default('active')` is invisible to that
     * in-memory instance until it's re-fetched. Setting it here means the
     * value is correct from the moment the model exists, both paths agree,
     * and it satisfies Model::shouldBeStrict() for a plain `new Teacher`.
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
