<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\DatabaseSwapper;
use App\Platform\Models\DatabaseSwap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A restored database put in place of a school's live one, in the background. One try; its steps are on the swap row. */
class SwapSchoolDatabase implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $swapId) {}

    public function uniqueId(): string
    {
        return (string) $this->swapId;
    }

    public function handle(DatabaseSwapper $swapper): void
    {
        $swap = DatabaseSwap::query()->find($this->swapId);

        if ($swap !== null && $swap->status === DatabaseSwap::QUEUED) {
            $swapper->run($swap);
        }
    }
}
