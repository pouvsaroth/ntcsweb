<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. HRM > Professional Development: a training session or course
 * (Training Calendar) and the staff assigned to it (training_participants).
 * See docs/hrm/professional-development.md.
 */
#[Fillable(['title', 'category', 'provider_type', 'trainer', 'mode', 'location', 'start_date', 'end_date', 'start_time', 'end_time', 'hours', 'cost', 'description', 'status', 'created_by'])]
class Training extends Model
{
    use Auditable;

    public const STATUSES = ['planned', 'completed', 'cancelled'];

    public const PROVIDER_TYPES = ['internal', 'external'];

    public const MODES = ['in_person', 'online'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'planned',
        'provider_type' => 'internal',
        'mode' => 'in_person',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'hours' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    /** @return HasMany<TrainingParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(TrainingParticipant::class);
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->title;
    }
}
