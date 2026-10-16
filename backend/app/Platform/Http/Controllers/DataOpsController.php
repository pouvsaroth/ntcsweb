<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\DataOps\BackupRunner;
use App\Platform\DataOps\PlatformDatabases;
use App\Platform\DataOps\SchoolMigrator;
use App\Platform\Models\Backup;
use App\Platform\Models\BackupSchedule;
use App\Platform\Models\MigrationRun;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Data ops: databases, backups (+ schedule), migrations, health.
 * See docs/backoffice/stage-3-data-ops.md.
 */
final class DataOpsController extends Controller
{
    public function __construct(
        private readonly PlatformDatabases $databases,
        private readonly BackupRunner $backups,
        private readonly SchoolMigrator $migrator,
        private readonly PlatformAudit $audit,
    ) {}

    public function databases(): JsonResponse
    {
        return ApiResponse::success($this->databases->report());
    }

    // ── Backups ─────────────────────────────────────────────────────────────

    public function backups(Request $request): JsonResponse
    {
        $backups = ApiQuery::for(Backup::query()->with('staff:id,name'), $request)
            ->filterable(['database', 'status', 'trigger'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success($backups->through(fn (Backup $b) => $this->backupRow($b)));
    }

    /** Back up now: one database, or every existing one. */
    public function backupNow(Request $request): JsonResponse
    {
        $data = $request->validate(['database' => ['required', 'string', 'max:100']]);
        $staff = $request->user('staff');

        $names = $data['database'] === 'all'
            ? array_values(array_filter(array_column($this->databases->all(), 'database'), fn ($n) => $this->databases->exists($n)))
            : [$this->knownExisting($data['database'])];

        $queued = array_map(fn (string $name) => $this->backups->queue($name, 'manual', $staff), $names);

        foreach ($queued as $backup) {
            $this->audit->record('backup.started', $backup, new: ['database' => $backup->database], tenantId: $backup->tenant_id);
        }

        return ApiResponse::created(array_map(fn (Backup $b) => $this->backupRow($b->fresh()), $queued));
    }

    public function download(Backup $backup): BinaryFileResponse
    {
        $disk = Storage::disk(config('platform.backup_disk'));
        abort_unless($backup->status === Backup::STATUS_OK && $backup->path !== null && $disk->exists($backup->path), 404);

        $this->audit->record('backup.downloaded', $backup, new: ['database' => $backup->database], tenantId: $backup->tenant_id);

        return response()->download($disk->path($backup->path), basename($backup->path), ['Content-Type' => 'application/octet-stream']);
    }

    public function destroyBackup(Backup $backup): JsonResponse
    {
        abort_if($backup->status === Backup::STATUS_RUNNING, 409, __('A backup that is still running cannot be deleted.'));

        $this->backups->deleteFiles($backup);
        $backup->delete();
        $this->audit->record('backup.deleted', $backup, ['database' => $backup->database, 'path' => $backup->path], tenantId: $backup->tenant_id);

        return ApiResponse::noContent();
    }

    public function schedule(): JsonResponse
    {
        return ApiResponse::success($this->scheduleRow(BackupSchedule::current()));
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'days' => ['present', 'array'],
            'days.*' => ['string', Rule::in(BackupSchedule::DAYS)],
            'at' => ['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'keep_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $schedule = BackupSchedule::current();
        $old = $schedule->only(['enabled', 'days', 'at', 'keep_days']);
        $schedule->fill(['enabled' => $data['enabled'], 'days' => array_values(array_unique($data['days'])), 'at' => $data['at'], 'keep_days' => $data['keep_days']])->save();
        $this->audit->record('backup.schedule_changed', $schedule, $old, $schedule->only(['enabled', 'days', 'at', 'keep_days']));

        return ApiResponse::success($this->scheduleRow($schedule));
    }

    // ── Migrations ──────────────────────────────────────────────────────────

    public function migrationRuns(Request $request): JsonResponse
    {
        $runs = ApiQuery::for(MigrationRun::query()->with(['tenant:id,name,slug', 'staff:id,name']), $request)
            ->filterable(['tenant_id', 'status', 'kind', 'batch'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success($runs->through(fn (MigrationRun $r) => [
            'id' => $r->id,
            'batch' => $r->batch,
            'kind' => $r->kind,
            'status' => $r->status,
            'school' => $r->tenant ? ['id' => $r->tenant->id, 'name' => $r->tenant->name, 'slug' => $r->tenant->slug] : null,
            'staff' => $r->staff?->name,
            'applied' => $r->applied,
            'error' => $r->error,
            'duration_ms' => $r->duration_ms,
            'created_at' => $r->created_at?->toIso8601String(),
        ]));
    }

    /** Migrate one school (tenant_id) or every active one ("all"). Schools without a database are skipped, not failed. */
    public function migrate(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required']]);

        $tenants = $data['tenant_id'] === 'all'
            ? Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])->orderBy('name')->get()
                ->filter(fn (Tenant $t) => $this->databases->exists($this->databases->nameFor($t)))
            : collect([Tenant::query()->findOrFail($data['tenant_id'])]);

        $runs = $this->migrator->queue($tenants, MigrationRun::KIND_MIGRATE, $request->user('staff'));
        $this->audit->record('school.migrate_started', null, new: ['schools' => $tenants->pluck('slug')->values()->all()]);

        return ApiResponse::created(['batch' => $runs[0]->batch ?? null, 'count' => count($runs)]);
    }

    /** An active (or suspended) school whose database is missing. */
    public function createDatabase(Request $request, Tenant $school): JsonResponse
    {
        if (! in_array($school->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            throw ValidationException::withMessages(['school' => __('Only an active or suspended school is given a database here — a new school is set up from Schools.')]);
        }
        if ($this->databases->exists($this->databases->nameFor($school))) {
            throw ValidationException::withMessages(['school' => __('This school already has its database.')]);
        }

        $runs = $this->migrator->queue([$school], MigrationRun::KIND_CREATE, $request->user('staff'));
        $this->audit->record('school.database_create_started', $school, tenantId: $school->getKey());

        return ApiResponse::created(['batch' => $runs[0]->batch, 'count' => 1]);
    }

    // ── Health ──────────────────────────────────────────────────────────────

    public function health(): JsonResponse
    {
        $central = DB::connection(config('tenancy.database.central_connection'));
        // Stored as UTC without a zone; sent with one, so the screen shows local time like every other date.
        $beat = $central->table('bo_heartbeats')->where('name', 'scheduler')->value('beat_at');
        $beat = $beat !== null ? \Illuminate\Support\Carbon::parse($beat, 'UTC') : null;
        $backupRoot = Storage::disk(config('platform.backup_disk'))->path('');

        try {
            $waiting = Queue::size();
        } catch (\Throwable) {
            $waiting = null;
        }

        return ApiResponse::success([
            'queue' => [
                'waiting' => $waiting,
                'failed' => $central->table('failed_jobs')->count(),
                // The message only — never a stack trace on a staff screen.
                'latest_failures' => $central->table('failed_jobs')->latest('failed_at')->limit(5)->get(['uuid', 'queue', 'exception', 'failed_at'])
                    ->map(fn ($row) => [
                        'id' => $row->uuid,
                        'queue' => $row->queue,
                        'message' => mb_strimwidth(strtok((string) $row->exception, "\n") ?: '', 0, 300, '…'),
                        'failed_at' => \Illuminate\Support\Carbon::parse($row->failed_at, 'UTC')->toIso8601String(),
                    ])->all(),
            ],
            'scheduler' => [
                'last_tick_at' => $beat?->toIso8601String(),
                'healthy' => $beat !== null && now()->diffInMinutes($beat, true) <= 5,
            ],
            'disk' => [
                'free_bytes' => is_dir($backupRoot) ? (int) disk_free_space($backupRoot) : null,
                'backups_bytes' => (int) Backup::query()->good()->sum('size_bytes'),
                'copy_disk' => config('platform.backup_copy_disk'),
            ],
        ]);
    }

    // ── Shapes ──────────────────────────────────────────────────────────────

    private function knownExisting(string $database): string
    {
        try {
            $this->databases->tenantFor($database);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['database' => __('That is not one of the platform\'s databases.')]);
        }
        if (! $this->databases->exists($database)) {
            throw ValidationException::withMessages(['database' => __('That database does not exist yet.')]);
        }

        return $database;
    }

    /** @return array<string, mixed> */
    private function backupRow(Backup $b): array
    {
        return [
            'id' => $b->id,
            'database' => $b->database,
            'tenant_id' => $b->tenant_id,
            'trigger' => $b->trigger,
            'staff' => $b->staff?->name,
            'status' => $b->status,
            'size_bytes' => $b->size_bytes,
            'duration_ms' => $b->duration_ms,
            'error' => $b->error,
            'copied' => $b->copied_at !== null,
            'copy_error' => $b->copy_error,
            'file' => $b->path ? basename($b->path) : null,
            'created_at' => $b->created_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleRow(BackupSchedule $s): array
    {
        return [
            'enabled' => $s->enabled,
            'days' => $s->days,
            'at' => $s->at,
            'keep_days' => $s->keep_days,
            'timezone' => config('platform.backup_timezone'),
            'last_run_at' => $s->last_run_at?->toIso8601String(),
            'next_run_at' => $s->nextRun(now())?->toIso8601String(),
            'copy_disk' => config('platform.backup_copy_disk'),
        ];
    }
}
