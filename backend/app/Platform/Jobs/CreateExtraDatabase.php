<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\DatabaseMaker;
use App\Platform\Models\ExtraDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Data ops > Databases: makes one "Other" database in the background. Its result is on its row. */
class CreateExtraDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $databaseId) {}

    public function handle(DatabaseMaker $maker): void
    {
        $row = ExtraDatabase::query()->find($this->databaseId);

        if ($row !== null && $row->status === ExtraDatabase::STATUS_CREATING) {
            $maker->make($row);
        }
    }
}
