<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Platform\DataOps\PgDump;

/** Stands in for pg_dump: writes a small file, or fails like pg_dump does. */
class FakePgDump extends PgDump
{
    /** @var list<string> */
    public array $dumped = [];

    /** @var array<string, string> database => error to throw */
    public array $failFor = [];

    public function dump(string $database, string $path): void
    {
        if (isset($this->failFor[$database])) {
            file_put_contents($path, 'partial');

            throw new \RuntimeException($this->failFor[$database]);
        }

        file_put_contents($path, "PGDMP fake dump of {$database}");
        $this->dumped[] = $database;
    }

    public function restoreInto(string $path, string $into): void
    {
        throw new \LogicException('Not used in tests.');
    }
}
