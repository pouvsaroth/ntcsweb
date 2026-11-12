<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Platform\DataOps\PgDump;

/** Stands in for pg_dump: writes a small file, or fails like pg_dump does. */
class FakePgDump extends PgDump
{
    /** @var list<string> */
    public array $dumped = [];

    /** @var array<string, array<string, mixed>|null> database => the server it was dumped with (null = this one) */
    public array $servers = [];

    /** @var array<string, string> database => error to throw */
    public array $failFor = [];

    /** @var array<string, list<array{name: string, mode: string}>|null> database => the tables picked (null = whole database) */
    public array $tables = [];

    public function dump(string $database, string $path, ?array $server = null, ?array $tables = null): void
    {
        $this->servers[$database] = $server;
        $this->tables[$database] = $tables;

        if (isset($this->failFor[$database])) {
            file_put_contents($path, 'partial');

            throw new \RuntimeException($this->failFor[$database]);
        }

        file_put_contents($path, "PGDMP fake dump of {$database}");
        $this->dumped[] = $database;
    }

    /** @var array<string, string> new database => the file restored into it */
    public array $restored = [];

    /** @var array<string, string> new database => error to throw */
    public array $failRestoreFor = [];

    public function restoreInto(string $path, string $into): void
    {
        if (isset($this->failRestoreFor[$into])) {
            throw new \RuntimeException($this->failRestoreFor[$into]);
        }

        $this->restored[$into] = (string) file_get_contents($path);
    }
}
