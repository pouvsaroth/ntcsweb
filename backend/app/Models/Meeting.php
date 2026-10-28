<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tenant-owned. Front Office > Meetings: a meeting's time and place, agenda,
 * staff participants (meeting_participants) and outside guests, and the
 * note written about it.
 */
#[Fillable(['title', 'meeting_date', 'start_time', 'end_time', 'location', 'agenda', 'minutes', 'status', 'organizer_staff_id', 'guests', 'created_by'])]
class Meeting extends Model
{
    use Auditable;

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'organizer_staff_id')->withTrashed();
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'meeting_participants')->withTimestamps()->withTrashed();
    }

    public function auditModule(): string
    {
        return 'Front Office';
    }
}
