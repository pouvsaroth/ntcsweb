<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Platform\Models\Backup;
use Illuminate\Support\Facades\Process;

/**
 * pg_dump / pg_restore against this server's own Postgres, with the
 * credentials from config/database.php — never from a request. A seam:
 * tests replace it so the suite never shells out.
 */
class PgDump
{
    /**
     * Custom-format dump of $database into $path. Throws with pg_dump's own
     * message on failure. $server: a separate server's host / port / login /
     * password / sslmode — otherwise this server's, from config.
     *
     * A separate server's login is no superuser, so the school tables' FORCEd
     * row-level security binds it even as their owner — and pg_dump's default
     * (row security off) is refused there. So: keep it on, and say which
     * school the session is for, which on that server is all of its rows.
     *
     * $tables: only these tables (a custom backup), each `full` (structure
     * and rows) or `structure` (no rows). Null: the whole database.
     *
     * @param  array{host: string, port: int, username: string, password: string, sslmode?: ?string, tenant_id?: int}|null  $server
     * @param  list<array{name: string, mode: string}>|null  $tables
     */
    public function dump(string $database, string $path, ?array $server = null, ?array $tables = null): void
    {
        $args = $server === null ? $this->connectionArgs() : [
            '--host='.$server['host'], '--port='.$server['port'], '--username='.$server['username'], '--no-password',
            '--enable-row-security',
        ];
        $env = ['PGPASSWORD' => $server['password'] ?? $this->credential('password')];
        if (! empty($server['sslmode'])) {
            $env['PGSSLMODE'] = $server['sslmode'];
        }
        if (isset($server['tenant_id'])) {
            $env['PGOPTIONS'] = '-c app.tenant_id='.(int) $server['tenant_id'];
        }

        $result = Process::timeout(3600)->env($env)->run([
            'pg_dump', ...$args, ...$this->tableArgs($tables), '--format=custom', '--no-owner', '--file='.$path, $database,
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

    /**
     * --table for each picked table; --exclude-table-data too for a
     * structure-only one. Quoted, so a name is matched exactly.
     *
     * @param  list<array{name: string, mode: string}>|null  $tables
     * @return list<string>
     */
    private function tableArgs(?array $tables): array
    {
        $args = [];
        foreach ($tables ?? [] as $table) {
            $pattern = 'public."'.str_replace('"', '""', $table['name']).'"';
            $args[] = '--table='.$pattern;
            if ($table['mode'] === Backup::TABLE_STRUCTURE) {
                $args[] = '--exclude-table-data='.$pattern;
            }
        }

        return $args;
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
