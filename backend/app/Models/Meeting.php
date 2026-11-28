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
#[Fillable(['title', 'meeting_date', 'start_time', 'end_time', 'location', 'agenda', 'minutes', 'status', 'organizer_staff_id', 'guests', 'guest_attendance', 'created_by'])]
class Meeting extends Model
{
    use Auditable;

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    /** Who came: per staff participant (pivot `attendance`) and per guest. */
    public const ATTENDANCE = ['present', 'late', 'absent', 'excused'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'guest_attendance' => 'array',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'organizer_staff_id')->withTrashed();
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'meeting_participants')->withPivot('attendance')->withTimestamps()->withTrashed();
    }

    /** @return list<string> the guest names, one per line of `guests` */
    public function guestNames(): array
    {
        $lines = preg_split('/\R/', (string) $this->guests) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), fn (string $g) => $g !== '')));
    }

    public function auditModule(): string
    {
        return 'Front Office';
    }
}
