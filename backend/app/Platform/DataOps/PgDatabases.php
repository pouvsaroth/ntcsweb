<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Whole databases on this server (CREATE / DROP / RENAME DATABASE) for Data
 * ops' "Other" databases and swapping one into a school. A seam: tests replace it, since CREATE DATABASE cannot
 * run inside the test's transaction.
 */
class PgDatabases
{
    public function __construct(private readonly TenantProvisioningService $provisioning) {}

    public function exists(string $name): bool
    {
        return $this->central()->table('pg_database')->where('datname', $name)->exists();
    }

    public function createEmpty(string $name): void
    {
        $this->central()->statement('CREATE DATABASE '.$this->ident($name));
    }

    /** A copy of the school template: every school table, no rows. */
    public function cloneTemplate(string $name): void
    {
        $this->provisioning->cloneTemplateInto($name);
    }

    /** Signs out its sessions, then drops it. */
    public function drop(string $name): void
    {
        $this->central()->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$name]);
        $this->central()->statement('DROP DATABASE IF EXISTS '.$this->ident($name));
    }

    /** A school's own database brought up to today's migrations (creates nothing: it exists). */
    public function migrateSchool(\App\Models\Tenant $tenant): void
    {
        $this->provisioning->provision($tenant);
    }

    /**
     * Renames a database. Postgres refuses while anyone is connected, so its
     * sessions are signed out first — a few tries, in case one reconnects.
     */
    public function rename(string $from, string $to): void
    {
        for ($try = 1; ; $try++) {
            $this->central()->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$from]);

            try {
                $this->central()->statement('ALTER DATABASE '.$this->ident($from).' RENAME TO '.$this->ident($to));

                return;
            } catch (QueryException $e) {
                if ($try >= 5 || ! str_contains($e->getMessage(), 'being accessed by other users')) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }

    /**
     * How many rows of one school a database holds in a few tables every
     * school fills (staff, students, academic years) — 0 means it is not
     * that school's data.
     */
    public function schoolRows(string $database, int $tenantId): int
    {
        $name = 'swap_check';
        config(['database.connections.'.$name => array_merge(
            config('database.connections.'.config('tenancy.database.central_connection')),
            ['database' => $database, 'search_path' => 'public'],
        )]);

        try {
            $db = DB::connection($name);
            $db->select("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
            $count = 0;
            foreach (['staff', 'students', 'academic_years'] as $table) {
                if ($db->getSchemaBuilder()->hasTable($table)) {
                    $count += $db->table($table)->where('tenant_id', $tenantId)->count();
                }
            }

            return $count;
        } finally {
            DB::purge($name);
        }
    }

    private function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
