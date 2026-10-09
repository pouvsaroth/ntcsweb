<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One weekday's hours of a shift — see the shift_days
 * migration and Shift::timesOn().
 *
 * @property int $day_of_week ISO: 1 = Monday … 7 = Sunday
 * @property string $start_time "HH:MM:SS"
 * @property string $end_time "HH:MM:SS"
 */
#[Fillable(['shift_id', 'day_of_week', 'start_time', 'end_time'])]
class ShiftDay extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
