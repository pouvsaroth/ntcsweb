<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Models\Backup;
use App\Platform\Models\BackupSchedule;
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

    /** @return list<array{database: string, tenant: ?Tenant}> */
    public function all(): array
    {
        $rows = [['database' => $this->centralName(), 'tenant' => null]];

        foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
            $rows[] = ['database' => $this->database->nameFor($tenant->slug), 'tenant' => $tenant];
        }

        return $rows;
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

    public function nameFor(Tenant $tenant): string
    {
        return $this->database->nameFor($tenant->slug);
    }

    public function exists(string $database): bool
    {
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
        $staleBefore = now()->subDays($this->intervalDays($schedule))->subHours(6);

        return array_map(function (array $row) use ($centralFiles, $tenantFiles, $lastGood, $schedule, $staleBefore) {
            $tenant = $row['tenant'];
            $name = $row['database'];
            $out = [
                'database' => $name,
                'kind' => $tenant ? 'school' : 'central',
                'school' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status] : null,
                'exists' => false,
                'size_bytes' => null,
                'pending_migrations' => null,
                'error' => null,
                'last_backup_at' => isset($lastGood[$name]) ? Carbon::parse($lastGood[$name])->toIso8601String() : null,
            ];

            try {
                $out['size_bytes'] = $this->database->sizeOf($name);
                $out['exists'] = $out['size_bytes'] !== null;
                if ($out['exists']) {
                    $applied = $tenant
                        ? $this->database->appliedMigrations($tenant)
                        : DB::connection(config('tenancy.database.central_connection'))->table('migrations')->pluck('migration')->all();
                    $out['pending_migrations'] = count(array_diff($tenant ? $tenantFiles : $centralFiles, $applied));
                }
            } catch (\Throwable $e) {
                $out['error'] = $e->getMessage();
            }

            // A school being set up, failed or archived is not expected to have
            // a working database; an active or suspended one is.
            $expected = $tenant === null || in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true);
            $stale = $out['last_backup_at'] === null || Carbon::parse($out['last_backup_at'])->lessThan($staleBefore);

            $out['flags'] = array_values(array_filter([
                $expected && ! $out['exists'] && $out['error'] === null ? 'missing' : null,
                $out['error'] !== null ? 'unreachable' : null,
                ($out['pending_migrations'] ?? 0) > 0 ? 'behind' : null,
                $out['exists'] && $schedule->enabled && $stale ? 'no_recent_backup' : null,
            ]));

            return $out;
        }, $this->all());
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
