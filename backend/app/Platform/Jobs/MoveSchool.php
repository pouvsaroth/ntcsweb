<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\SchoolMover;
use App\Platform\Models\SchoolMove;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One school's move between placements, in the background. One try; its steps are on the move row. */
class MoveSchool implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $moveId) {}

    public function uniqueId(): string
    {
        return (string) $this->moveId;
    }

    public function handle(SchoolMover $mover): void
    {
        $move = SchoolMove::query()->find($this->moveId);

        if ($move !== null && $move->status === SchoolMove::QUEUED) {
            $mover->run($move);
        }
    }
}
