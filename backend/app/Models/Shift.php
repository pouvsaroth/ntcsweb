<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. Working hours (HRM > Attendance & Time > Shift). An end time
 * before the start time is an overnight shift ending the next day. The
 * grace minutes are how late / how early is still on time.
 *
 * @property string $start_time "HH:MM:SS"
 * @property string $end_time "HH:MM:SS"
 */
#[Fillable(['code', 'name', 'start_time', 'end_time', 'break_minutes', 'late_grace_minutes', 'early_leave_grace_minutes', 'color', 'is_active'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'break_minutes' => 0,
        'late_grace_minutes' => 0,
        'early_leave_grace_minutes' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_leave_grace_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isOvernight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /** Scheduled working minutes: start to end, less the break. */
    public function workMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($minutes <= 0) {
            $minutes += 24 * 60;
        }

        return max(0, $minutes - $this->break_minutes);
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
