<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Front Office > Visitors: everyone who came in, with check-in
 * and check-out times. From an appointment (appointment_id) or a walk-in.
 */
#[Fillable(['appointment_id', 'name', 'phone', 'id_number', 'organization', 'purpose', 'host_staff_id', 'people_count', 'badge_no', 'check_in_at', 'check_out_at', 'note', 'created_by'])]
class Visitor extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'people_count' => 'integer',
        ];
    }

    /** Checked in and not yet out. */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereNull('check_out_at');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'host_staff_id')->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function auditModule(): string
    {
        return 'Front Office';
    }
}
