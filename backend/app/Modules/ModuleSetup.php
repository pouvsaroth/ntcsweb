<?php

declare(strict_types=1);

namespace App\Modules;

use App\Models\Tenant;
use App\Platform\Billing\SchoolModules;

/**
 * Each module's starting data in a customer's database (docs/architecture/multi-product.md):
 * at setup (SchoolProvisioner) and when the customer later gets more
 * modules (a new system or plan — EnsureModuleData). Every module's seed()
 * is safe to run again.
 */
final class ModuleSetup
{
    public function __construct(private readonly SchoolModules $modules) {}

    /** The customer's modules (all of them for a customer with no plan, or NewTech's own school). */
    public function seedFor(Tenant $tenant): void
    {
        $this->modules->forget();
        $enabled = $this->modules->enabled((int) $tenant->getKey());

        foreach (ModuleRegistry::all() as $code => $module) {
            if ($enabled === null || in_array($code, $enabled, true)) {
                $module->seed($tenant);
            }
        }
    }
}
