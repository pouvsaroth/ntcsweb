<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\BackupRunner;
use App\Platform\DataOps\PlatformDatabases;
use App\Platform\DataOps\TableBrowser;
use App\Platform\Models\Backup;
use App\Platform\Models\PlatformStaff;
use App\Platform\Support\PlatformAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Data ops > Tables > Clear all data, in the background: back up every table
 * it will empty (when asked), then TRUNCATE. Runs in the queue worker — the
 * same place every backup is written — and leaves its outcome in the cache
 * under the token the screen polls.
 */
class ClearTable implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $token,
        public readonly string $database,
        public readonly string $table,
        public readonly bool $cascade,
        public readonly bool $restartIds,
        public readonly bool $backupFirst,
        public readonly ?int $staffId,
    ) {}

    public static function cacheKey(string $token): string
    {
        return 'bo:clear-table:'.$token;
    }

    /** @param  array<string, mixed>  $state */
    public static function put(string $token, array $state): void
    {
        Cache::put(self::cacheKey($token), $state, now()->addDay());
    }

    public function handle(TableBrowser $browser, BackupRunner $backups, PlatformDatabases $databases, PlatformAudit $audit): void
    {
        $staff = $this->staffId ? PlatformStaff::query()->find($this->staffId) : null;
        $tenantId = $databases->tenantFor($this->database)?->getKey();
        $backup = null;

        try {
            if ($this->backupFirst) {
                $tables = array_map(fn (string $t) => ['name' => $t, 'mode' => Backup::TABLE_FULL], $browser->clearSet($this->database, $this->table, $this->cascade));
                $backup = $backups->runTablesNow($this->database, $tables, $staff);
                $audit->record('backup.started', $backup, new: ['database' => $this->database, 'tables' => $backup->tables], tenantId: $backup->tenant_id, actor: $staff);
                if ($backup->status !== Backup::STATUS_OK) {
                    self::put($this->token, ['status' => 'failed', 'error' => __('The backup before clearing failed, so nothing was cleared: :error', ['error' => $backup->error]), 'backup_id' => $backup->id]);

                    return;
                }
            }

            $cleared = $browser->clear($this->database, $this->table, $this->cascade, $this->restartIds);
        } catch (ValidationException $e) {
            self::put($this->token, ['status' => 'failed', 'error' => collect($e->errors())->flatten()->first(), 'backup_id' => $backup?->id]);

            return;
        } catch (\Throwable $e) {
            self::put($this->token, ['status' => 'failed', 'error' => $e->getMessage(), 'backup_id' => $backup?->id]);

            throw $e;
        }

        $audit->record('data.table.cleared', new: [
            'database' => $this->database,
            'table' => $this->table,
            'cleared' => $cleared,
            'restart_ids' => $this->restartIds,
            'backup_id' => $backup?->id,
        ], tenantId: $tenantId, actor: $staff);

        self::put($this->token, ['status' => 'done', 'cleared' => $cleared, 'backup_id' => $backup?->id]);
    }
}
