<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. A day — or run of days — off for everyone (HRM >
 * Attendance & Time > Holidays). A one-day holiday has the same start and
 * end date.
 */
#[Fillable(['name', 'holiday_type', 'start_date', 'end_date', 'description'])]
class Holiday extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    /** The Base Data category `holiday_type` takes its codes from. */
    public const TYPE_CATEGORY = 'HOLIDAY_TYPE';

    /** Not a column: student permissions saving this holiday just cancelled (HolidayController). */
    public ?int $cancelledPermissions = null;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public static function covering(CarbonInterface $date): ?self
    {
        $day = $date->toDateString();

        return self::query()->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day)->first();
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
