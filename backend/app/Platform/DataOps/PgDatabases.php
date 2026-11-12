<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Whole databases on this server (CREATE / DROP DATABASE) for Data ops'
 * "Other" databases. A seam: tests replace it, since CREATE DATABASE cannot
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

    private function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
