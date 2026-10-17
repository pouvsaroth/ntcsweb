<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Console\Command;

/**
 * Runs on every deploy (see .github/workflows/deploy.yml) so a new tenant
 * migration under database/migrations/tenant reaches every existing school's
 * database automatically — the same reasoning as the central `migrate
 * --force` step it sits next to. Safe to run repeatedly: provisioning an
 * already-set-up tenant is a no-op beyond applying whatever tenant
 * migrations are newly pending, see TenantProvisioningService.
 */
class ProvisionAllTenantDatabasesCommand extends Command
{
    protected $signature = 'tenants:provision-all-databases';

    protected $description = "Create or update every active tenant's own database and run its tenant migrations";

    public function handle(TenantProvisioningService $provisioning): int
    {
        // The template every dedicated school is cloned from, and the shared
        // database — both belong to no single school; kept migrated so a new
        // school starts up to date (docs/backoffice/stage-4-database-placement.md).
        foreach ([$provisioning->templateName(), $provisioning->sharedName()] as $database) {
            $this->components->info("Preparing \"{$database}\"...");
            $provisioning->prepareSchoollessDatabase($database);
        }

        $tenants = Tenant::active()->get();

        foreach ($tenants as $tenant) {
            $this->components->info("Provisioning \"{$tenant->name}\"...");
            $provisioning->provision($tenant);
        }

        $this->components->info("Done — {$tenants->count()} tenant(s) provisioned.");

        return self::SUCCESS;
    }
}
