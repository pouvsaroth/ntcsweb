<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\SchoolConnection;
use App\Support\Tenancy\SchoolDatabaseConfig;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Brings a school's data store up to date, wherever it lives
 * (docs/backoffice/stage-4-database-placement.md):
 *
 *   shared     the shared database (created the first time, migrated with no
 *              school set) — the school's own rows come from the seeders
 *   dedicated  its own database, CLONED from the template (tn_template) the
 *              first time — far faster than migrating from nothing — then
 *              migrated for whatever landed since
 *   separate   the database staff created on another server: only migrated;
 *              the platform never creates anything there
 *
 * Deliberately a separate, explicit step from Tenant creation itself (not a
 * model event): creating a database is a heavyweight, failable operation, and
 * every test that does `Tenant::factory()->create()` must keep doing exactly
 * that with no side effect. Safe to call again at any time.
 */
final class TenantProvisioningService
{
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly TenantContext $context,
    ) {}

    public function provision(Tenant $tenant): void
    {
        /** @var SchoolDatabaseConfig $config */
        $config = $tenant->database();
        $placement = $config->placement();

        if ($placement->isShared()) {
            $this->prepareSchoollessDatabase($placement->database);
        } elseif ($config->isCreatedByPlatform() && ! $this->exists($placement->database)) {
            $this->prepareSchoollessDatabase($this->templateName());
            $this->cloneTemplate($placement->database);
        }

        // As the database owner (migrations create and alter tables), and
        // with this school set on the connection, so a migration that stamps
        // existing rows with tenant_id stamps them with THIS school.
        SchoolConnection::asOwner(fn () => $this->context->runFor($tenant, function () use ($tenant) {
            $this->databases->createTenantConnection($tenant);

            try {
                $this->migrate($tenant->slug);
            } finally {
                $this->databases->purgeTenantConnection();
            }
        }));
    }

    /**
     * The template and the shared database: created if missing and migrated
     * with no school set (they belong to no single school). Every deploy runs
     * this, so a cloned database starts with every migration already in it.
     */
    public function prepareSchoollessDatabase(string $database): void
    {
        if (! $this->exists($database)) {
            DB::connection($this->central())->statement('CREATE DATABASE '.$this->ident($database));
        }

        SchoolConnection::asSchoolless(function () use ($database) {
            config(['database.connections.tenant' => array_merge(
                config('database.connections.'.$this->central()),
                ['database' => $database, 'search_path' => 'public'],
            )]);

            try {
                $this->migrate($database);
            } finally {
                $this->databases->purgeTenantConnection();
            }
        });
    }

    public function templateName(): string
    {
        return (string) config('tenancy.database.template_database');
    }

    public function sharedName(): string
    {
        return (string) config('tenancy.database.shared_database');
    }

    public function exists(string $database): bool
    {
        return DB::connection($this->central())->table('pg_database')->where('datname', $database)->exists();
    }

    /**
     * A database on a separate server that staff created: migrated with no
     * school set (it may hold several schools' rows one day, like any other
     * data store), using its own login — never this server's.
     *
     * @param  array<string, mixed>  $connection
     */
    public function prepareSeparateDatabase(array $connection): void
    {
        SchoolConnection::asSchoolless(function () use ($connection) {
            config(['database.connections.tenant' => $connection]);

            try {
                $this->migrate($connection['host'].'/'.$connection['database']);
            } finally {
                $this->databases->purgeTenantConnection();
            }
        });
    }

    public function cloneTemplateInto(string $database): void
    {
        $this->cloneTemplate($database);
    }

    /** CREATE DATABASE … TEMPLATE needs the template to have no open sessions. */
    private function cloneTemplate(string $database): void
    {
        $central = DB::connection($this->central());
        $central->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$this->templateName()]);
        $central->statement('CREATE DATABASE '.$this->ident($database).' TEMPLATE '.$this->ident($this->templateName()));
    }

    private function migrate(string $what): void
    {
        $exit = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--realpath' => false,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new \RuntimeException("Migrating {$what} failed: ".trim(Artisan::output()));
        }
    }

    private function central(): string
    {
        return (string) config('tenancy.database.central_connection');
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
