<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Models\Backup;
use App\Platform\Models\BackupSchedule;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every database the platform owns: the central one, then each school's.
 * The list comes from `tenants`, never from pg_database — a database nobody
 * registered is not ours, and a registered one that is missing must show as
 * missing rather than not at all.
 */
final class PlatformDatabases
{
    public function __construct(private readonly SchoolDatabase $database) {}

    public function centralName(): string
    {
        return (string) config('database.connections.'.config('tenancy.database.central_connection').'.database');
    }

    /**
     * Every data store, once: the central database, the template, the shared
     * database, then each school that has a database of its own (dedicated or
     * on a separate server). Shared schools live inside the shared database's
     * row (`schools`), not as rows of their own.
     *
     * @return list<array{database: string, kind: string, tenant: ?Tenant, schools?: list<Tenant>}>
     */
    public function all(): array
    {
        $tenants = Tenant::query()->orderBy('name')->get();
        $placements = SchoolPlacement::query()->whereIn('tenant_id', $tenants->modelKeys())->get()->keyBy('tenant_id');
        $shared = [];
        $own = [];

        foreach ($tenants as $tenant) {
            $placement = $placements[$tenant->id] ?? SchoolPlacement::of($tenant);
            if ($placement->isShared()) {
                $shared[] = $tenant;
            } else {
                $own[] = ['database' => $placement->database, 'kind' => 'school', 'tenant' => $tenant];
            }
        }

        return [
            ['database' => $this->centralName(), 'kind' => 'central', 'tenant' => null],
            ['database' => (string) config('tenancy.database.template_database'), 'kind' => 'template', 'tenant' => null],
            ['database' => (string) config('tenancy.database.shared_database'), 'kind' => 'shared', 'tenant' => null, 'schools' => $shared],
            ...$own,
        ];
    }

    /** @throws \InvalidArgumentException for a name that is not one of ours */
    public function tenantFor(string $database): ?Tenant
    {
        foreach ($this->all() as $row) {
            if ($row['database'] === $database) {
                return $row['tenant'];
            }
        }

        throw new \InvalidArgumentException("{$database} is not a platform database.");
    }

    public function exists(string $database): bool
    {
        foreach ($this->all() as $row) {
            if ($row['database'] === $database && $row['tenant'] !== null && SchoolPlacement::of($row['tenant'])->isSeparate()) {
                return $this->database->hasDataStore($row['tenant']);
            }
        }

        return $this->database->exists($database);
    }

    /**
     * The Databases screen: each database probed, a failure costing only its
     * own row.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $centralFiles = $this->migrationFiles(database_path('migrations'));
        $tenantFiles = $this->migrationFiles(database_path('migrations/tenant'));
        $lastGood = Backup::query()->good()
            ->selectRaw('database, max(finished_at) as finished_at')
            ->groupBy('database')
            ->pluck('finished_at', 'database');
        $schedule = BackupSchedule::current();
        $staleBefore = $this->staleBefore($schedule);

        return array_map(function (array $row) use ($centralFiles, $tenantFiles, $lastGood, $schedule, $staleBefore) {
            $tenant = $row['tenant'];
            $name = $row['database'];
            $placement = $tenant ? SchoolPlacement::of($tenant) : null;
            $out = [
                'database' => $name,
                'kind' => $row['kind'],
                'school' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status] : null,
                'placement' => $placement?->mode,
                'server' => $placement?->isSeparate() ? $placement->host.':'.$placement->port : null,
                'schools' => array_map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status], $row['schools'] ?? []),
                'exists' => false,
                'size_bytes' => null,
                'pending_migrations' => null,
                'error' => null,
                'last_backup_at' => isset($lastGood[$name]) ? Carbon::parse($lastGood[$name])->toIso8601String() : null,
            ];

            try {
                if ($placement?->isSeparate()) {
                    $out['exists'] = $this->database->hasDataStore($tenant);
                    $out['size_bytes'] = $out['exists'] ? $this->database->within($tenant, fn () => (int) DB::connection('tenant')->selectOne('select pg_database_size(current_database()) as s')->s) : null;
                } else {
                    $out['size_bytes'] = $this->database->sizeOf($name);
                    $out['exists'] = $out['size_bytes'] !== null;
                }
                if ($out['exists']) {
                    $applied = match ($row['kind']) {
                        'central' => DB::connection(config('tenancy.database.central_connection'))->table('migrations')->pluck('migration')->all(),
                        'school' => $this->database->appliedMigrations($tenant),
                        default => $this->database->appliedMigrationsIn($name),
                    };
                    $out['pending_migrations'] = count(array_diff($row['kind'] === 'central' ? $centralFiles : $tenantFiles, $applied));
                }
            } catch (\Throwable $e) {
                $out['error'] = $e->getMessage();
            }

            // A school being set up, failed or archived is not expected to have
            // a working database; an active or suspended one is.
            $expected = match ($row['kind']) {
                'school' => in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true),
                'shared' => ($row['schools'] ?? []) !== [],
                default => true,
            };
            $stale = $out['last_backup_at'] === null || Carbon::parse($out['last_backup_at'])->lessThan($staleBefore);

            $out['flags'] = array_values(array_filter([
                $expected && ! $out['exists'] && $out['error'] === null ? 'missing' : null,
                $out['error'] !== null ? 'unreachable' : null,
                ($out['pending_migrations'] ?? 0) > 0 ? 'behind' : null,
                // The template holds no data and is never backed up on purpose.
                $out['exists'] && $schedule->enabled && $stale && $row['kind'] !== 'template' ? 'no_recent_backup' : null,
            ]));

            return $out;
        }, $this->all());
    }

    /** A good backup older than this is not recent: the schedule's longest gap, plus 6 hours. */
    public function staleBefore(?BackupSchedule $schedule = null): Carbon
    {
        return now()->subDays($this->intervalDays($schedule ?? BackupSchedule::current()))->subHours(6);
    }

    /** @return list<string> the school migrations every school database should have */
    public function schoolMigrationFiles(): array
    {
        return $this->migrationFiles(database_path('migrations/tenant'));
    }

    /** Longest gap between two scheduled days, so "recent" follows the schedule. */
    private function intervalDays(BackupSchedule $schedule): int
    {
        $index = array_values(array_keys(array_intersect(BackupSchedule::DAYS, $schedule->days ?? [])));
        if (count($index) <= 1) {
            return 7;
        }

        $gaps = [];
        foreach ($index as $i => $day) {
            $next = $index[($i + 1) % count($index)];
            $gaps[] = ($next - $day + 7) % 7 ?: 7;
        }

        return max($gaps);
    }

    /** @return list<string> */
    private function migrationFiles(string $dir): array
    {
        return array_map(fn ($f) => basename($f, '.php'), glob($dir.'/*.php') ?: []);
    }
}
