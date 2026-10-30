<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-owned. One coaching / mentoring session: topic, notes and agreed actions. */
#[Fillable(['coaching_id', 'session_date', 'duration_minutes', 'topic', 'notes', 'action_items', 'created_by'])]
class CoachingSession extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'duration_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Coaching, $this> */
    public function coaching(): BelongsTo
    {
        return $this->belongsTo(Coaching::class);
    }

    public function auditModule(): string
    {
        return 'HRM';
    }

    public function auditDisplayName(): string
    {
        return (string) $this->topic;
    }
}
