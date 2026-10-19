<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Billing\StudentLimit;
use App\Platform\Billing\Subscriptions;
use App\Platform\DataOps\PlatformDatabases;
use App\Platform\Models\Backup;
use App\Platform\Models\BackupSchedule;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Models\SchoolStatusChange;
use App\Platform\Services\SchoolDatabase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One school's health in one place (docs/backoffice/stage-6-support.md, 6a).
 *
 * Each line has a state: ok, warn, bad, or off (does not apply). `report()`
 * opens the school's database (size, migrations, students); `dots()` reads
 * only central tables, so the Schools list never touches a school database.
 */
final class SchoolHealth
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const BAD = 'bad';

    public const OFF = 'off';

    /** No sign-in by anyone at the school for this many days → warn. */
    public const QUIET_DAYS = 14;

    /** Failed jobs within this many days → warn. */
    public const FAILED_JOB_DAYS = 7;

    /** Active users = signed in within this many days. */
    public const ACTIVE_USER_DAYS = 30;

    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly PlatformDatabases $databases,
        private readonly Subscriptions $subscriptions,
        private readonly TenantContext $context,
    ) {}

    /** @return array{state: string, checked_at: string, lines: list<array<string, mixed>>} */
    public function report(Tenant $tenant): array
    {
        $running = $this->runs($tenant);
        $signIns = $this->signIns([$tenant])[$tenant->id] ?? null;

        $lines = [
            $this->statusLine($tenant),
            $this->databaseLine($tenant, $running),
            $this->backupLine($tenant, $this->lastBackups([$tenant])[$tenant->id] ?? null, $running),
            $this->planLine($this->subscriptions->summary($tenant)),
            $this->studentsLine($tenant, $running),
            $this->usersLine($tenant),
            $this->signInLine($signIns, $running),
            $this->failedJobsLine($this->failedJobs([$tenant->id])[$tenant->id] ?? null, withLatest: true),
        ];

        return [
            'state' => $this->worst(array_column($lines, 'state')),
            'checked_at' => now()->toIso8601String(),
            'lines' => $lines,
        ];
    }

    /**
     * The Schools list dot, from central tables only: status, backup, plan,
     * last sign-in, failed jobs.
     *
     * @param  iterable<Tenant>  $tenants
     * @param  array<int, array<string, mixed>>  $billing  Subscriptions::summaries() for the same schools
     * @return array<int, array{state: string, reasons: list<string>}>
     */
    public function dots(iterable $tenants, array $billing): array
    {
        $tenants = collect($tenants);
        $ids = $tenants->map(fn (Tenant $t) => $t->getKey())->values()->all();
        $backups = $this->lastBackups($tenants->all());
        $signIns = $this->signIns($tenants->all());
        $failed = $this->failedJobs($ids);

        $out = [];
        foreach ($tenants as $tenant) {
            $running = $this->runs($tenant);
            $lines = [
                $this->statusLine($tenant, withHistory: false),
                $this->backupLine($tenant, $backups[$tenant->id] ?? null, $running),
                $this->planLine($billing[$tenant->id] ?? ['flag' => 'no_plan', 'subscription' => null]),
                $this->signInLine($signIns[$tenant->id] ?? null, $running),
                $this->failedJobsLine($failed[$tenant->id] ?? null, withLatest: false),
            ];
            $state = $this->worst(array_column($lines, 'state'));
            $out[$tenant->id] = [
                'state' => $state,
                'reasons' => array_values(array_map(
                    fn ($l) => $l['key'],
                    array_filter($lines, fn ($l) => in_array($l['state'], [self::WARN, self::BAD], true)),
                )),
            ];
        }

        return $out;
    }

    // ── Lines ───────────────────────────────────────────────────────────────

    /** Active or suspended: the school is expected to have working data. */
    private function runs(Tenant $tenant): bool
    {
        return in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true);
    }

    /** @return array<string, mixed> */
    private function statusLine(Tenant $tenant, bool $withHistory = true): array
    {
        $last = $withHistory
            ? SchoolStatusChange::query()->with('staff:id,name')->where('tenant_id', $tenant->id)->latest('id')->first()
            : null;

        return [
            'key' => 'status',
            'state' => match ($tenant->status) {
                Tenant::STATUS_ACTIVE => self::OK,
                Tenant::STATUS_PROVISIONING => self::WARN,
                default => self::BAD,
            },
            'status' => $tenant->status,
            'since' => $last?->created_at?->toIso8601String(),
            'reason' => $last?->reason,
            'by' => $last?->staff?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function databaseLine(Tenant $tenant, bool $running): array
    {
        $placement = SchoolPlacement::of($tenant);
        $line = [
            'key' => 'database',
            'state' => self::OFF,
            'mode' => $placement->mode,
            'database' => $placement->database,
            'server' => $placement->isSeparate() ? $placement->host.':'.$placement->port : null,
            'exists' => null,
            'size_bytes' => null,
            'pending_migrations' => null,
            'error' => null,
        ];
        if (! $running) {
            return $line;
        }

        try {
            $line['exists'] = $this->database->hasDataStore($tenant);
            if ($line['exists']) {
                $line['size_bytes'] = $placement->isSeparate()
                    ? $this->database->within($tenant, fn () => (int) DB::connection('tenant')->selectOne('select pg_database_size(current_database()) as s')->s)
                    : $this->database->sizeOf($placement->database);
                $applied = $placement->isShared()
                    ? $this->database->appliedMigrationsIn($placement->database)
                    : $this->database->appliedMigrations($tenant);
                $line['pending_migrations'] = count(array_diff($this->databases->schoolMigrationFiles(), $applied));
            }
        } catch (\Throwable $e) {
            report($e);
            // The first line only: a connection error, never a stack trace.
            $line['error'] = mb_strimwidth(strtok($e->getMessage(), "\n") ?: '', 0, 300, '…');
        }

        $line['state'] = match (true) {
            $line['error'] !== null, $line['exists'] === false => self::BAD,
            ($line['pending_migrations'] ?? 0) > 0 => self::WARN,
            default => self::OK,
        };

        return $line;
    }

    /** @return array<string, mixed> */
    private function backupLine(Tenant $tenant, ?Backup $backup, bool $running): array
    {
        $schedule = BackupSchedule::current();
        $stale = $backup === null || $backup->finished_at === null || $backup->finished_at->lessThan($this->databases->staleBefore($schedule));
        $copyFailed = $backup !== null && $backup->copy_error !== null;

        return [
            'key' => 'backup',
            'state' => match (true) {
                ! $running => self::OFF,
                $backup === null => self::BAD,
                $stale && $schedule->enabled => self::BAD,
                $copyFailed => self::WARN,
                default => self::OK,
            },
            'at' => $backup?->finished_at?->toIso8601String(),
            'database' => $backup?->database,
            'copied' => $backup?->copied_at !== null,
            'copy_error' => $backup?->copy_error,
            'schedule_enabled' => (bool) $schedule->enabled,
        ];
    }

    /** @param array<string, mixed> $summary Subscriptions::summary() @return array<string, mixed> */
    private function planLine(array $summary): array
    {
        return [
            'key' => 'plan',
            'state' => match ($summary['flag']) {
                'none' => self::OK,
                'expiring', 'no_plan' => self::WARN,
                default => self::BAD,
            },
            'flag' => $summary['flag'],
            'subscription' => $summary['subscription'],
        ];
    }

    /** @return array<string, mixed> */
    private function studentsLine(Tenant $tenant, bool $running): array
    {
        $usage = null;
        $error = null;
        if ($running) {
            try {
                $usage = $this->database->within($tenant, fn () => $this->context->runFor($tenant, fn () => app(StudentLimit::class)->usage()));
            } catch (\Throwable $e) {
                report($e);
                $error = true;
            }
        }
        $limit = $usage['limit'] ?? null;
        $active = $usage['active'] ?? null;

        return [
            'key' => 'students',
            'state' => match (true) {
                $usage === null => self::OFF,
                $limit === null => self::OK,
                $active >= $limit => self::BAD,
                $active >= $limit * StudentLimit::WARN_AT => self::WARN,
                default => self::OK,
            },
            'active' => $active,
            'limit' => $limit,
            'unavailable' => $error === true,
        ];
    }

    /** @return array<string, mixed> */
    private function usersLine(Tenant $tenant): array
    {
        // last_login_at is written in the school's own time (console requests run in it).
        $since = now($tenant->timezone ?: 'UTC')->subDays(self::ACTIVE_USER_DAYS)->format('Y-m-d H:i:s');
        $row = User::query()->inTenant($tenant)
            ->selectRaw('count(*) as total, count(*) filter (where last_login_at >= ?) as active', [$since])
            ->toBase()->first();

        return [
            'key' => 'users',
            'state' => self::OK,
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'active_days' => self::ACTIVE_USER_DAYS,
        ];
    }

    /** @param array{at: Carbon, name: string}|null $last @return array<string, mixed> */
    private function signInLine(?array $last, bool $running): array
    {
        return [
            'key' => 'sign_in',
            'state' => match (true) {
                ! $running => self::OFF,
                $last === null || $last['at']->lessThan(now()->subDays(self::QUIET_DAYS)) => self::WARN,
                default => self::OK,
            },
            'at' => $last !== null ? $last['at']->toIso8601String() : null,
            'name' => $last['name'] ?? null,
            'quiet_days' => self::QUIET_DAYS,
        ];
    }

    /** @param array{count: int, message: ?string, at: ?string}|null $failed @return array<string, mixed> */
    private function failedJobsLine(?array $failed, bool $withLatest): array
    {
        $count = $failed['count'] ?? 0;

        return [
            'key' => 'failed_jobs',
            'state' => $count > 0 ? self::WARN : self::OK,
            'count' => $count,
            'days' => self::FAILED_JOB_DAYS,
            'latest' => $withLatest && $count > 0 ? ['message' => $failed['message'], 'at' => $failed['at']] : null,
        ];
    }

    /** @param list<string> $states */
    private function worst(array $states): string
    {
        foreach ([self::BAD, self::WARN] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return self::OK;
    }

    // ── Batched central reads ───────────────────────────────────────────────

    private function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    /**
     * The newest good backup covering each school: one of its own (its
     * database, or its rows alone), or of the shared database it lives in.
     *
     * @param  list<Tenant>  $tenants
     * @return array<int, Backup>
     */
    private function lastBackups(array $tenants): array
    {
        if ($tenants === []) {
            return [];
        }
        $ids = array_map(fn (Tenant $t) => $t->id, $tenants);
        $placements = SchoolPlacement::query()->whereIn('tenant_id', $ids)->get()->keyBy('tenant_id');
        $databases = [];
        foreach ($tenants as $tenant) {
            $databases[$tenant->id] = ($placements[$tenant->id] ?? SchoolPlacement::of($tenant))->database;
        }

        $candidates = Backup::query()->good()
            ->where(fn ($q) => $q->whereIn('tenant_id', $ids)->orWhereIn('database', array_values(array_unique($databases))))
            ->whereIn('id', fn ($sub) => $sub->from('bo_backups')->selectRaw('max(id)')->where('status', Backup::STATUS_OK)
                ->groupBy('database', 'tenant_id', 'format'))
            ->get();

        $out = [];
        foreach ($tenants as $tenant) {
            $best = $candidates
                ->filter(fn (Backup $b) => $b->tenant_id === $tenant->id || ($b->database === $databases[$tenant->id] && ($b->tenant_id === null || $b->format === Backup::FORMAT_DUMP)))
                ->sortByDesc(fn (Backup $b) => $b->finished_at?->getTimestamp() ?? 0)
                ->first();
            if ($best !== null) {
                $out[$tenant->id] = $best;
            }
        }

        return $out;
    }

    /**
     * The most recent sign-in at each school, and who. last_login_at is
     * written in the school's own time (console requests run in it), so it is
     * read back in that zone.
     *
     * @param  list<Tenant>  $tenants
     * @return array<int, array{at: Carbon, name: string}>
     */
    private function signIns(array $tenants): array
    {
        if ($tenants === []) {
            return [];
        }
        $zones = [];
        foreach ($tenants as $tenant) {
            $zones[$tenant->id] = $tenant->timezone ?: 'UTC';
        }

        return User::query()->whereIn('tenant_id', array_keys($zones))->whereNotNull('last_login_at')
            ->selectRaw('distinct on (tenant_id) tenant_id, name, last_login_at')
            ->orderBy('tenant_id')->orderByDesc('last_login_at')
            ->toBase()->get()
            ->mapWithKeys(fn ($row) => [(int) $row->tenant_id => [
                'at' => Carbon::parse($row->last_login_at, $zones[(int) $row->tenant_id])->utc(),
                'name' => $row->name,
            ]])
            ->all();
    }

    /**
     * Failed queue jobs per school within FAILED_JOB_DAYS, tagged when queued
     * (AppServiceProvider::configureQueuePayload).
     *
     * @param  list<int>  $ids
     * @return array<int, array{count: int, message: ?string, at: ?string}>
     */
    private function failedJobs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->central()->table('failed_jobs')
            ->selectRaw("(payload::jsonb->>'ntcs_tenant_id')::bigint as tenant_id, exception, failed_at")
            ->whereRaw("(payload::jsonb->>'ntcs_tenant_id')::bigint in (".implode(',', array_map('intval', $ids)).')')
            ->where('failed_at', '>=', now()->subDays(self::FAILED_JOB_DAYS))
            ->orderByDesc('failed_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->tenant_id;
            if (! isset($out[$id])) {
                $out[$id] = [
                    'count' => 0,
                    // The message only — never a stack trace on a staff screen.
                    'message' => mb_strimwidth(strtok((string) $row->exception, "\n") ?: '', 0, 300, '…'),
                    'at' => Carbon::parse($row->failed_at, 'UTC')->toIso8601String(),
                ];
            }
            $out[$id]['count']++;
        }

        return $out;
    }
}
