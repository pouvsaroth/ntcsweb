<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Console\Command;

/**
 * Creates a school's own physical database and migrates it — see
 * TenantProvisioningService's docblock for why this is a deliberate,
 * explicit step rather than something that happens automatically when a
 * Tenant row is created.
 */
class ProvisionTenantDatabaseCommand extends Command
{
    protected $signature = 'tenants:provision-database {tenant : Tenant id or slug}';

    protected $description = "Create a school's own database and run its tenant migrations";

    public function handle(TenantProvisioningService $provisioning): int
    {
        $identifier = $this->argument('tenant');

        $tenant = Tenant::query()
            ->where('id', ctype_digit((string) $identifier) ? (int) $identifier : -1)
            ->orWhere('slug', $identifier)
            ->first();

        if ($tenant === null) {
            $this->components->error("No tenant found matching \"{$identifier}\".");

            return self::FAILURE;
        }

        $this->components->info("Provisioning a database for \"{$tenant->name}\"...");

        $provisioning->provision($tenant);

        $this->components->info("Done — {$tenant->database()->getName()} is ready.");

        return self::SUCCESS;
    }
}
