<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One staff member's pay in a payroll run, kept as worked out
 * (see PayrollCalculator) — amounts in their salary currency. `lines` is
 * the payslip itself (earnings, deductions, the school's NSSF share);
 * `details` the working behind it. Rebuilt while the run is a draft;
 * fixed once it's sent for approval.
 */
#[Fillable([
    'payroll_run_id', 'staff_id', 'currency', 'monthly_basic', 'basic_pay', 'allowances', 'bonuses', 'overtime_pay', 'gross_pay',
    'attendance_deduction', 'other_deductions', 'social_security_employee', 'social_security_employer', 'tax', 'loan_deduction',
    'adjustment', 'adjustment_note', 'net_pay', 'lines', 'details',
    'payment_method', 'bank_name', 'bank_account_name', 'bank_account_number',
])]
class Payslip extends Model
{
    protected $connection = 'tenant';

    /** The money columns, for totals. */
    public const AMOUNTS = [
        'basic_pay', 'allowances', 'bonuses', 'overtime_pay', 'gross_pay', 'attendance_deduction', 'other_deductions',
        'social_security_employee', 'social_security_employer', 'tax', 'loan_deduction', 'adjustment', 'net_pay',
    ];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::AMOUNTS, 'float'),
            'monthly_basic' => 'float',
            'lines' => 'array',
            'details' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
