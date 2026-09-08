<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Creates a school's own physical database and brings it up to date —
 * deliberately a separate, explicit step from Tenant creation itself (not a
 * model event), for the same reason LeaveRequestService keeps its side
 * effects out of Eloquent hooks: creating a whole database is a heavyweight,
 * failable operation, and every test in this app that does
 * `Tenant::factory()->create()` — there are hundreds — must keep doing
 * exactly that with no side effect, not suddenly spin up a real database.
 *
 * Only the tables under database/migrations/tenant actually live in the
 * database this creates; everything else stays in the shared one, untouched.
 *
 * Safe to call again for an already-provisioned tenant: it only creates the
 * database once, then re-running just applies whatever tenant migrations
 * have landed since — the same "migrate picks up new modules" flow every
 * future module conversion needs.
 */
final class TenantProvisioningService
{
    public function __construct(
        private readonly DatabaseManager $databases,
    ) {}

    public function provision(Tenant $tenant): void
    {
        $manager = $tenant->database()->manager();

        if (! $manager->databaseExists($tenant->database()->getName())) {
            $manager->createDatabase($tenant);
        }

        $this->databases->createTenantConnection($tenant);

        try {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--realpath' => false,
                '--force' => true,
            ]);
        } finally {
            $this->databases->purgeTenantConnection();
        }
    }
}
