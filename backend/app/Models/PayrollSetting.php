<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, one row (see current()). HRM > Payroll's rules: working days
 * a month and hours a day — a monthly salary's daily and hourly rate —
 * overtime multipliers, what attendance deducts, and Tax on Salary's
 * dependant allowances (riel a month) and non-resident rate.
 */
#[Fillable([
    'working_days_per_month', 'hours_per_day',
    'overtime_normal_rate', 'overtime_rest_day_rate', 'overtime_holiday_rate',
    'deduct_absence', 'deduct_unpaid_leave', 'late_deduction_mode', 'late_grace_minutes', 'late_amount_usd', 'late_amount_khr', 'deduct_early_leave',
    'tax_spouse_allowance', 'tax_child_allowance', 'tax_non_resident_rate',
])]
class PayrollSetting extends Model
{
    use Auditable;

    public const LATE_NONE = 'none';

    public const LATE_PER_MINUTE = 'per_minute';

    public const LATE_PER_OCCURRENCE = 'per_occurrence';

    public const LATE_MODES = [self::LATE_NONE, self::LATE_PER_MINUTE, self::LATE_PER_OCCURRENCE];

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'working_days_per_month' => 'float',
            'hours_per_day' => 'float',
            'overtime_normal_rate' => 'float',
            'overtime_rest_day_rate' => 'float',
            'overtime_holiday_rate' => 'float',
            'deduct_absence' => 'boolean',
            'deduct_unpaid_leave' => 'boolean',
            'late_grace_minutes' => 'integer',
            'late_amount_usd' => 'float',
            'late_amount_khr' => 'float',
            'deduct_early_leave' => 'boolean',
            'tax_spouse_allowance' => 'float',
            'tax_child_allowance' => 'float',
            'tax_non_resident_rate' => 'float',
        ];
    }

    /** The school's settings — the migration inserts the row; this recreates it with the defaults if it was ever removed. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? tap(new self, fn (self $settings) => $settings->save())->refresh();
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
