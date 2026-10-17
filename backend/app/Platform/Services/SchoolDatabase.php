<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Tenant;
use App\Platform\Models\SchoolPlacement;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * The physical side of a school: where its data lives — the shared database,
 * its own database, or a database on a separate server (SchoolPlacement).
 *
 * A seam on purpose — tests replace it (FakeSchoolDatabase) so the suite
 * never creates real databases on the Postgres server, while the rest of
 * provisioning runs for real.
 */
class SchoolDatabase
{
    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly DatabaseManager $databases,
    ) {}

    public function nameFor(string $slug): string
    {
        return config('tenancy.database.name_prefix').$slug;
    }

    public function placementOf(Tenant $tenant): SchoolPlacement
    {
        return SchoolPlacement::of($tenant);
    }

    /**
     * Is the place this school's data lives there? Shared: the shared
     * database exists. Dedicated: its database exists on this server.
     * Separate: we can connect to it with the stored login.
     */
    public function hasDataStore(Tenant $tenant): bool
    {
        $placement = $this->placementOf($tenant);

        if (! $placement->isSeparate()) {
            return $this->exists($placement->database);
        }

        try {
            return (bool) $this->within($tenant, fn () => DB::connection('tenant')->selectOne('select 1 as ok')->ok);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Is there already a database with this name on the server? */
    public function exists(string $name): bool
    {
        return DB::connection(config('tenancy.database.central_connection'))
            ->table('pg_database')
            ->where('datname', $name)
            ->exists();
    }

    /** Create if missing, then apply every tenant migration. Idempotent. */
    public function createAndMigrate(Tenant $tenant): void
    {
        $this->provisioning->provision($tenant);
    }

    /** Bytes on disk, or null when the database does not exist. */
    public function sizeOf(string $name): ?int
    {
        if (! $this->exists($name)) {
            return null;
        }

        return (int) DB::connection(config('tenancy.database.central_connection'))
            ->selectOne('select pg_database_size(?) as size', [$name])->size;
    }

    /**
     * Migration names already applied in this school's database.
     *
     * @return list<string>
     */
    public function appliedMigrations(Tenant $tenant): array
    {
        return $this->within($tenant, fn () => DB::connection('tenant')->table('migrations')->pluck('migration')->all());
    }

    /**
     * Migrations applied in a database that belongs to no single school (the
     * template, the shared database) on this server.
     *
     * @return list<string>
     */
    public function appliedMigrationsIn(string $database): array
    {
        $name = 'platform_probe';
        config(['database.connections.'.$name => array_merge(config('database.connections.'.config('tenancy.database.central_connection')), ['database' => $database])]);

        try {
            return DB::connection($name)->table('migrations')->pluck('migration')->all();
        } finally {
            DB::purge($name);
        }
    }

    /** Point the `tenant` connection at this school's database while $callback runs. */
    public function within(Tenant $tenant, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        $this->databases->createTenantConnection($tenant);

        try {
            return $callback();
        } finally {
            $this->databases->purgeTenantConnection();
        }
    }
}
