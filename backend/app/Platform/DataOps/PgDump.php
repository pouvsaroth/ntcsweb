<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use Illuminate\Support\Facades\Process;

/**
 * pg_dump / pg_restore against this server's own Postgres, with the
 * credentials from config/database.php — never from a request. A seam:
 * tests replace it so the suite never shells out.
 */
class PgDump
{
    /** Custom-format dump of $database into $path. Throws with pg_dump's own message on failure. */
    public function dump(string $database, string $path): void
    {
        $result = Process::timeout(3600)->env(['PGPASSWORD' => $this->credential('password')])->run([
            'pg_dump', ...$this->connectionArgs(), '--format=custom', '--no-owner', '--file='.$path, $database,
        ]);

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'pg_dump failed.');
        }
    }

    /** Restore $path into a NEW database $into (created here; createdb refuses one that exists). */
    public function restoreInto(string $path, string $into): void
    {
        $env = ['PGPASSWORD' => $this->credential('password')];

        $create = Process::env($env)->run(['createdb', ...$this->connectionArgs(), $into]);
        if (! $create->successful()) {
            throw new \RuntimeException(trim($create->errorOutput()) ?: "Could not create {$into}.");
        }

        $restore = Process::timeout(3600)->env($env)->run([
            'pg_restore', ...$this->connectionArgs(), '--no-owner', '--dbname='.$into, $path,
        ]);
        if (! $restore->successful()) {
            throw new \RuntimeException(trim($restore->errorOutput()) ?: 'pg_restore failed.');
        }
    }

    /** @return list<string> */
    private function connectionArgs(): array
    {
        return [
            '--host='.$this->credential('host'),
            '--port='.$this->credential('port'),
            '--username='.$this->credential('username'),
            '--no-password',
        ];
    }

    private function credential(string $key): string
    {
        return (string) config('database.connections.'.config('tenancy.database.central_connection').'.'.$key);
    }
}
