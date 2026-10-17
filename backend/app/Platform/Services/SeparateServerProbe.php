<?php

declare(strict_types=1);

namespace App\Platform\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Test connection" for a school on a separate Postgres server: can we log in
 * with what staff pasted, and can that login create the school's tables?
 * Never stores anything; the password is only ever held for the probe.
 */
class SeparateServerProbe
{
    public const SSL_MODES = ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string, sslmode?: string}  $server
     * @return array{ok: bool, message: string, superuser?: bool, can_create?: bool, version?: string}
     */
    public function probe(array $server): array
    {
        $name = 'separate_probe';
        config(['database.connections.'.$name => [
            'driver' => 'pgsql',
            'host' => $server['host'],
            'port' => (int) $server['port'],
            'database' => $server['database'],
            'username' => $server['username'],
            'password' => $server['password'],
            'sslmode' => $server['sslmode'] ?? 'prefer',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ]]);

        try {
            $row = DB::connection($name)->selectOne(<<<'SQL'
                select current_user as login,
                       (select rolsuper from pg_roles where rolname = current_user) as superuser,
                       has_schema_privilege(current_user, 'public', 'CREATE') as can_create,
                       split_part(version(), ' ', 2) as version
                SQL);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->readable($e->getMessage())];
        } finally {
            DB::purge($name);
        }

        if (! $row->can_create) {
            return [
                'ok' => false,
                'message' => "Connected as {$row->login}, but it cannot create tables in the public schema. Make it the owner of that database (ALTER DATABASE … OWNER TO {$row->login}) or GRANT CREATE ON SCHEMA public.",
                'superuser' => (bool) $row->superuser,
                'can_create' => false,
                'version' => $row->version,
            ];
        }

        return [
            'ok' => true,
            'message' => "Connected as {$row->login} (PostgreSQL {$row->version}).",
            'superuser' => (bool) $row->superuser,
            'can_create' => true,
            'version' => $row->version,
        ];
    }

    /** Postgres's own words, minus the noise around them. */
    private function readable(string $message): string
    {
        if (preg_match('/FATAL:\s+(.+?)(?:\s+\(Connection:|$)/s', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/SQLSTATE\[[^\]]+\]\s+\[\d+\]\s+(.+?)(?:\s+\(Connection:|$)/s', $message, $m)) {
            return trim($m[1]);
        }

        return mb_strimwidth($message, 0, 300, '…');
    }
}
