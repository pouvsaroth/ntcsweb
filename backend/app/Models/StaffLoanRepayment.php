<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. Money paid back on a loan/advance — taken by a payroll run
 * (`method` payroll, `payroll_run_id` set; stage 3) or paid in cash.
 */
#[Fillable(['staff_loan_id', 'amount', 'paid_on', 'method', 'payroll_run_id', 'note', 'created_by'])]
class StaffLoanRepayment extends Model
{
    public const METHOD_PAYROLL = 'payroll';

    public const METHOD_CASH = 'cash';

    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StaffLoan::class, 'staff_loan_id');
    }
}
