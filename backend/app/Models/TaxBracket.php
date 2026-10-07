<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned. One band of monthly Tax on Salary, in riel: the part of
 * taxable pay above `min_amount` (up to `max_amount`; null = no top) is
 * taxed at `rate` %. Saved as a whole list (see PayrollRulesController).
 */
#[Fillable(['min_amount', 'max_amount', 'rate'])]
class TaxBracket extends Model
{
    protected $connection = 'tenant';

    protected function casts(): array
    {
        return [
            'min_amount' => 'float',
            'max_amount' => 'float',
            'rate' => 'float',
        ];
    }
}
