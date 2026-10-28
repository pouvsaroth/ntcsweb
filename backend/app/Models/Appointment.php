<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant-owned. Front Office > Appointments: a booked visit. Check in turns
 * it into a Visitor record (status checked_in); checking that visitor out
 * completes it.
 */
#[Fillable(['visitor_name', 'phone', 'email', 'organization', 'purpose', 'appointment_date', 'start_time', 'end_time', 'host_staff_id', 'status', 'note', 'created_by'])]
class Appointment extends Model
{
    use Auditable;

    public const STATUSES = ['scheduled', 'checked_in', 'completed', 'cancelled', 'no_show'];

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'host_staff_id')->withTrashed();
    }

    public function visitor(): HasOne
    {
        return $this->hasOne(Visitor::class);
    }

    public function auditModule(): string
    {
        return 'Front Office';
    }
}
