<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Demo\Demos;
use Illuminate\Console\Command;

/**
 * Hourly (docs/backoffice/stage-12-demo.md): switch off website demo
 * accounts past their days. The back office record stays.
 */
final class DemosTickCommand extends Command
{
    protected $signature = 'platform:demos-tick';

    protected $description = 'Switch off website demo accounts that have run out';

    public function handle(Demos $demos): int
    {
        $ended = $demos->endExpired();
        $this->info("Ended {$ended} demo(s).");

        return self::SUCCESS;
    }
}
