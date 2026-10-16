<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\DataOps\PgDump;
use App\Platform\Models\Backup;
use App\Platform\Support\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Restore a back-office backup into a NEW database — never over a live one.
 * Owner's decision: restore is a server command, not a button. Pointing a
 * school at the restored database, or swapping it for the live one, stays a
 * deliberate step on the server. See docs/backoffice/restore.md.
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'platform:restore-backup
        {backup : The backup id (Data ops → Backups)}
        {--into= : Name of the NEW database to restore into, e.g. tn_newtech_restore}';

    protected $description = 'Restore a backup into a new database (never over an existing one)';

    public function handle(PgDump $pgDump, PlatformAudit $audit): int
    {
        $backup = Backup::query()->find($this->argument('backup'));
        $into = (string) $this->option('into');

        if ($backup === null || $backup->status !== Backup::STATUS_OK || $backup->path === null) {
            $this->components->error('No good backup with that id.');

            return self::FAILURE;
        }
        if (! preg_match('/^[a-z][a-z0-9_-]{2,62}$/', $into)) {
            $this->components->error('Give --into a new database name: lowercase letters, digits, _ and -.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('platform.backup_disk'));
        if (! $disk->exists($backup->path)) {
            $this->components->error("The file {$backup->path} is not on the backups disk.");

            return self::FAILURE;
        }

        $this->components->info("Restoring {$backup->database} from {$backup->finished_at} into {$into}…");

        try {
            $pgDump->restoreInto($disk->path($backup->path), $into);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $audit->record('backup.restored', $backup, new: ['into' => $into], tenantId: $backup->tenant_id);
        $this->components->info("Done. {$into} holds {$backup->database} as of {$backup->finished_at}. Nothing points at it yet.");

        return self::SUCCESS;
    }
}
