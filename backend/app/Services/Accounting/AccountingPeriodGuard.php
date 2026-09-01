<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Blocks a new financial posting from landing inside a closed period — see
 * AccountingPeriod's docblock. Checked by FinancialTransactionService and
 * ExpenseService right before they post/pay, never by a model event (a
 * closed period must reject a *new* backdated entry, not silently skip it).
 */
final class AccountingPeriodGuard
{
    public function assertOpen(Tenant $tenant, CarbonInterface $date): void
    {
        $period = $date->format('Y-m');

        $closed = AccountingPeriod::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('period', $period)
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'date' => "The accounting period {$period} is closed. This cannot be posted or backdated into it.",
            ]);
        }
    }
}
