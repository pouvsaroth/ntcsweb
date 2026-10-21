<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Account;
use App\Models\LookupCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Platform\Models\School;
use App\Platform\Models\SchoolProvisioningLog;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * Sets a new school up, step by step, logging each one. Runs in the
 * ProvisionSchool job — never inside an HTTP request.
 *
 * Every step is idempotent, so Retry simply runs them all again: the ones
 * that already succeeded find their work done and pass straight through.
 *
 *   database   create tn_<slug> if missing, run the tenant migrations
 *   seed       roles + permissions, teacher position, chart of accounts, base data
 *   health     read the seed back through the school's own connection
 *   activate   status → active
 *   owner      the owner gets School Admin and their invite
 */
final class SchoolProvisioner
{
    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly SchoolStatus $status,
        private readonly SchoolOwners $owners,
        private readonly TenantContext $context,
    ) {}

    public function run(Tenant $tenant): void
    {
        $school = School::for($tenant);
        $attempt = $school->provisioning_attempts + 1;
        $school->forceFill(['provisioning_attempts' => $attempt, 'status_detail' => null])->save();

        try {
            $this->step($tenant, $attempt, 'database', fn () => $this->database->createAndMigrate($tenant));
            $this->step($tenant, $attempt, 'seed', fn () => $this->seed($tenant));
            $this->step($tenant, $attempt, 'health', fn () => $this->healthCheck($tenant));
            $this->step($tenant, $attempt, 'activate', function () use ($tenant, $school) {
                $this->status->move($tenant, Tenant::STATUS_ACTIVE);
                $school->forceFill(['activated_at' => now()])->save();
            });
            $this->step($tenant, $attempt, 'owner', function () use ($tenant, $school) {
                $owner = $school->fresh()->owner ?? throw new \RuntimeException('No owner was recorded for this school.');
                $this->owners->grantSchoolAdmin($tenant, $owner);
                // An owner who chose a password on the website's sign-up form needs no invite.
                if ($owner->status === \App\Models\User::STATUS_INVITED) {
                    $this->owners->invite($tenant->fresh());
                }
            });
        } catch (\Throwable $e) {
            $school->forceFill(['status_detail' => $e->getMessage()])->save();
            if ($tenant->fresh()->status === Tenant::STATUS_PROVISIONING) {
                $this->status->move($tenant->fresh(), Tenant::STATUS_FAILED, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * An existing school whose database is missing (Data ops → Create
     * database): the database, seed and health steps only — its status and
     * owner are left exactly as they are. Logged as a provisioning attempt.
     */
    public function createDatabase(Tenant $tenant): void
    {
        $school = School::for($tenant);
        $attempt = $school->provisioning_attempts + 1;
        $school->forceFill(['provisioning_attempts' => $attempt])->save();

        $this->step($tenant, $attempt, 'database', fn () => $this->database->createAndMigrate($tenant));
        $this->step($tenant, $attempt, 'seed', fn () => $this->seed($tenant));
        $this->step($tenant, $attempt, 'health', fn () => $this->healthCheck($tenant));
    }

    private function step(Tenant $tenant, int $attempt, string $name, callable $work): void
    {
        $started = hrtime(true);

        try {
            $work();
        } catch (\Throwable $e) {
            $this->log($tenant, $attempt, $name, 'failed', $e->getMessage(), $started);

            throw $e;
        }

        $this->log($tenant, $attempt, $name, 'ok', null, $started);
    }

    private function seed(Tenant $tenant): void
    {
        // Roles live in the central database; the rest in the school's own.
        // Core: every customer, whatever its systems.
        (new RolePermissionSeeder)->seedTenant($tenant);
        (new ChartOfAccountsSeeder)->seedForTenant($tenant);
        (new BaseDataSeeder)->seedTenant($tenant);
        // Then each of its modules' own starting data (e.g. Academics: the
        // "Teacher" position) — docs/architecture/multi-product.md.
        app(\App\Modules\ModuleSetup::class)->seedFor($tenant);
    }

    private function healthCheck(Tenant $tenant): void
    {
        $roles = Role::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count();
        if ($roles < 4) {
            throw new \RuntimeException("Expected the 4 system roles, found {$roles}.");
        }

        [$accounts, $lookups] = $this->database->within($tenant, fn () => $this->context->runFor($tenant, fn () => [
            Account::query()->count(),
            LookupCategory::query()->count(),
        ]));

        if ($accounts === 0 || $lookups === 0) {
            throw new \RuntimeException("The school's database did not read back its seed (accounts: {$accounts}, lookups: {$lookups}).");
        }
    }

    private function log(Tenant $tenant, int $attempt, string $step, string $status, ?string $message, int $started): void
    {
        SchoolProvisioningLog::query()->create([
            'tenant_id' => $tenant->getKey(),
            'attempt' => $attempt,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
