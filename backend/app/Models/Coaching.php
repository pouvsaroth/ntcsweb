<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A coach or mentor paired with a staff member around a goal,
 * optionally following a classroom observation, with the sessions they hold.
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['type', 'coach_staff_id', 'staff_id', 'classroom_observation_id', 'goal', 'description', 'start_date', 'end_date', 'status', 'outcome', 'created_by'])]
class Coaching extends Model
{
    use Auditable;

    public const TYPES = ['coaching', 'mentoring'];

    public const STATUSES = ['active', 'completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'type' => 'coaching',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<Staff, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'coach_staff_id')->withTrashed();
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    /** @return BelongsTo<ClassroomObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(ClassroomObservation::class, 'classroom_observation_id');
    }

    /** @return HasMany<CoachingSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(CoachingSession::class);
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->goal;
    }
}
