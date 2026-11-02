<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned. One shift's monthly pay on a "pay by shift" salary — see the
 * staff_salary_shifts migration. The amount is in the salary's currency.
 */
#[Fillable(['staff_salary_id', 'shift_id', 'amount'])]
class StaffSalaryShift extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function salary(): BelongsTo
    {
        return $this->belongsTo(StaffSalary::class, 'staff_salary_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
