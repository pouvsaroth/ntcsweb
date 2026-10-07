<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\StaffSalaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. A staff member's basic salary from a date on (HRM > Payroll >
 * Basic salary) — a raise is a new row, so the history stays. The salary on
 * a given day is the row with the latest `effective_from` on or before it
 * (see scopeEffectiveOn()). Currency is per staff member, USD or KHR.
 */
#[Fillable(['staff_id', 'basic_salary', 'currency', 'salary_structure_id', 'effective_from', 'payment_method', 'bank_name', 'bank_account_name', 'bank_account_number', 'note', 'created_by'])]
class StaffSalary extends Model
{
    /** @use HasFactory<StaffSalaryFactory> */
    use Auditable, HasFactory;

    public const PAYMENT_BANK = 'bank';

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_METHODS = [self::PAYMENT_BANK, self::PAYMENT_CASH];

    protected $connection = 'tenant';

    protected $attributes = [
        'payment_method' => self::PAYMENT_BANK,
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    /** Each staff member's row in effect on this date — the latest one starting on or before it. */
    public function scopeEffectiveOn(Builder $query, string $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->whereNotExists(function ($later) use ($date) {
                $later->selectRaw('1')
                    ->from('staff_salaries as later')
                    ->whereColumn('later.staff_id', 'staff_salaries.staff_id')
                    ->whereColumn('later.effective_from', '>', 'staff_salaries.effective_from')
                    ->whereDate('later.effective_from', '<=', $date);
            });
    }

    public function auditModule(): string
    {
        return 'Payroll';
    }
}
