<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Tenant;
use App\Platform\DataOps\BackupRunner;
use App\Platform\Models\Backup;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolMove;
use App\Platform\Support\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Delete a customer for good (back office → Customers → Delete): its data
 * where it lives (dedicated database dropped; its rows in the shared or a
 * separate database deleted), its user accounts, its files, its billing and
 * history rows, and — unless kept — its backups. Cannot be undone.
 *
 * Kept on purpose: the back office audit log (it records this deletion) and
 * website sign-up rows (their school link is cleared).
 */
final class SchoolEraser
{
    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly BackupRunner $backups,
        private readonly PlatformAudit $audit,
    ) {}

    public function erase(Tenant $tenant, PlatformStaff $staff, bool $keepBackups = false): void
    {
        if ($tenant->slug === config('platform.website_school')) {
            throw ValidationException::withMessages(['confirm' => __('This is NewTech\'s own website school; it cannot be deleted.')]);
        }
        if ($tenant->status === Tenant::STATUS_PROVISIONING
            || SchoolMove::query()->where('tenant_id', $tenant->id)->whereIn('status', [SchoolMove::QUEUED, SchoolMove::RUNNING])->exists()) {
            throw ValidationException::withMessages(['confirm' => __('Wait until its setup or data move has finished.')]);
        }

        $id = $tenant->getKey();
        $placement = $this->database->placementOf($tenant);
        $record = ['name' => $tenant->name, 'slug' => $tenant->slug, 'placement' => $placement->mode, 'database' => $placement->database, 'kept_backups' => $keepBackups];

        // 1. Its data where it lives. A failure stops here, before anything central is gone.
        $this->database->erase($tenant);

        // 2. Backups (files on every backup disk, then rows) unless kept.
        if (! $keepBackups) {
            Backup::query()->where('tenant_id', $id)->each(function (Backup $backup) {
                $this->backups->deleteFiles($backup);
                $backup->delete();
            });
        }

        // 3. Central rows. Users first: their tenant link would otherwise be
        // cleared, leaving accounts with no school (which is how platform
        // accounts look). Everything else follows the tenant row (cascade).
        $central = DB::connection($tenant->getConnectionName());
        $central->transaction(function () use ($central, $id, $tenant) {
            $userIds = $central->table('users')->where('tenant_id', $id)->pluck('id');
            if ($userIds->isNotEmpty()) {
                $central->table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $userIds)->delete();
                if ($central->getSchemaBuilder()->hasTable('sessions')) {
                    $central->table('sessions')->whereIn('user_id', $userIds)->delete();
                }
                $central->table('users')->whereIn('id', $userIds)->delete();
            }
            $tenant->forceDelete();
        });

        // 4. Its uploaded files.
        foreach (['public', 'local'] as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory('tenants/'.$id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->audit->record('school.deleted', null, $record, tenantId: $id, actor: $staff);
    }
}
