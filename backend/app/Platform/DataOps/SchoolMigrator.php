<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Jobs\RunSchoolMigration;
use App\Platform\Models\MigrationRun;
use App\Platform\Models\PlatformStaff;
use App\Platform\Services\SchoolDatabase;
use App\Platform\Services\SchoolProvisioner;
use Illuminate\Support\Str;

/**
 * Bring schools' databases up to date from the back office — what the deploy
 * does for every school, here per school and visible. "Create" is for an
 * active school whose database is missing: Stage 2's database + seed +
 * health steps, without touching its status or owner.
 */
final class SchoolMigrator
{
    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly SchoolProvisioner $provisioner,
    ) {}

    /**
     * One run row per school, all in one batch, each its own job — one school
     * failing never stops the rest.
     *
     * @param  iterable<Tenant>  $tenants
     * @return list<MigrationRun>
     */
    public function queue(iterable $tenants, string $kind, ?PlatformStaff $staff = null): array
    {
        $batch = (string) Str::uuid();
        $runs = [];

        foreach ($tenants as $tenant) {
            $run = MigrationRun::query()->create([
                'batch' => $batch,
                'tenant_id' => $tenant->getKey(),
                'kind' => $kind,
                'staff_id' => $staff?->getKey(),
                'status' => 'queued',
            ]);
            RunSchoolMigration::dispatch($run->id);
            $runs[] = $run;
        }

        return $runs;
    }

    public function run(MigrationRun $run): void
    {
        $tenant = $run->tenant;
        $started = hrtime(true);
        $run->forceFill(['status' => 'running'])->save();

        try {
            if ($run->kind === MigrationRun::KIND_CREATE) {
                $this->provisioner->createDatabase($tenant);
                $applied = count($this->database->appliedMigrations($tenant));
            } else {
                if (! $this->database->exists($this->database->nameFor($tenant->slug))) {
                    throw new \RuntimeException('The school has no database yet — use Create database.');
                }
                $before = count($this->database->appliedMigrations($tenant));
                $this->database->createAndMigrate($tenant);
                $applied = count($this->database->appliedMigrations($tenant)) - $before;
            }

            $run->forceFill(['status' => 'ok', 'applied' => $applied, 'duration_ms' => $this->ms($started)])->save();
        } catch (\Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => $this->ms($started)])->save();
        }
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
