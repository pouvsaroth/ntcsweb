<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Student <-> Class, with the metadata that makes it more than
 * a bare pivot: when they joined, and whether they're still active in it.
 * Modelled as a full Eloquent model (its own `id`, queryable on its own)
 * rather than a `belongsToMany` pivot, so it can carry that metadata and be
 * looked up directly without always going through one side's relation.
 *
 * @property int $tenant_id
 * @property int $student_id
 * @property int $class_id
 * @property string $status
 */
#[Fillable(['student_id', 'class_id', 'enrolled_at', 'status'])]
class Enrollment extends Model
{
    use BelongsToTenant, HasFactory;

    /** @use HasFactory<EnrollmentFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DROPPED = 'dropped';

    /** PHP-level mirror of the column's DB default — see Teacher for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
