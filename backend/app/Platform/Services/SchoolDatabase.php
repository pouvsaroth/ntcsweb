<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * The physical side of a school: its own database `tn_<slug>`.
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
