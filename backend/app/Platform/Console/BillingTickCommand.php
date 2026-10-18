<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Billing\BillingSuspensions;
use Illuminate\Console\Command;

/**
 * Daily, from the scheduler (routes/console.php): plans past their end become
 * expired; schools unpaid past the grace days are suspended for billing
 * (docs/backoffice/stage-5-billing.md, 5e). Safe to run more than once a day.
 */
class BillingTickCommand extends Command
{
    protected $signature = 'platform:billing-tick';

    protected $description = 'Expire ended plans and suspend schools unpaid past the grace days';

    public function handle(BillingSuspensions $suspensions): int
    {
        $result = $suspensions->tick();

        $this->components->info("Expired {$result['expired']} plan(s); suspended {$result['suspended']} school(s).");

        return self::SUCCESS;
    }
}
