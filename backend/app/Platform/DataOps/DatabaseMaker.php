<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Platform\Jobs\CreateExtraDatabase;
use App\Platform\Models\Backup;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolPlacement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Data ops > Databases: databases staff make in the back office ("Other"),
 * on this server only — empty, a copy of the school template, a copy of
 * another of our databases (dumped and restored, so a live one is never
 * locked), or restored from a backup / an uploaded .dump file — always into
 * a NEW database, never over one. Made in the background; deleting one is
 * allowed only while no school uses it.
 */
final class DatabaseMaker
{
    /** Never a name staff can take. */
    private const RESERVED = ['postgres', 'template0', 'template1'];

    public function __construct(
        private readonly PlatformDatabases $databases,
        private readonly PgDatabases $server,
        private readonly PgDump $pgDump,
    ) {}

    /** Refuses a name that is malformed, reserved, one of ours, or already on the server. */
    public function assertFreeName(string $name, string $field = 'name'): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! preg_match(ExtraDatabase::NAME_PATTERN, $name)) {
            $fail(__('Use 3 to 63 lowercase letters, digits and _, starting with a letter.'));
        }
        if (in_array($name, self::RESERVED, true) || in_array($name, array_column($this->databases->all(), 'database'), true)) {
            $fail(__('That name is taken by another database.'));
        }
        if ($this->onServer($name)) {
            $fail(__('A database with that name already exists on the server.'));
        }
    }

    /** A copy's source: one of ours, on this server, that exists. */
    public function assertCopySource(string $database): void
    {
        try {
            $tenant = $this->databases->tenantFor($database);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['source_database' => __('Pick one of the databases in the list.')]);
        }
        if ($tenant !== null && SchoolPlacement::of($tenant)->isSeparate()) {
            throw ValidationException::withMessages(['source_database' => __('A school on a separate server cannot be copied here.')]);
        }
        if (! $this->onServer($database)) {
            throw ValidationException::withMessages(['source_database' => __('That database does not exist.')]);
        }
    }

    /** Records it and makes it in the background. */
    public function queue(string $name, string $source, ?string $sourceDatabase, ?PlatformStaff $staff, ?string $note = null, array $extra = []): ExtraDatabase
    {
        $row = ExtraDatabase::query()->create([
            'name' => $name,
            'source' => $source,
            'source_database' => $sourceDatabase,
            'status' => ExtraDatabase::STATUS_CREATING,
            'staff_id' => $staff?->id,
            'note' => $note,
            ...$extra,
        ]);

        CreateExtraDatabase::dispatch($row->id);

        return $row;
    }

    /**
     * The background work (CreateExtraDatabase). A failure leaves no
     * half-made database behind; an uploaded file is removed either way.
     */
    public function make(ExtraDatabase $row): void
    {
        try {
            match ($row->source) {
                ExtraDatabase::SOURCE_EMPTY => $this->server->createEmpty($row->name),
                ExtraDatabase::SOURCE_TEMPLATE => $this->server->cloneTemplate($row->name),
                ExtraDatabase::SOURCE_COPY => $this->copy((string) $row->source_database, $row->name),
                ExtraDatabase::SOURCE_BACKUP => $this->pgDump->restoreInto($this->backupFile($row), $row->name),
                ExtraDatabase::SOURCE_UPLOAD => $this->pgDump->restoreInto($this->uploadFile($row), $row->name),
                default => throw new \RuntimeException("Unknown source {$row->source}."),
            };
            $row->forceFill(['status' => ExtraDatabase::STATUS_READY, 'error' => null, 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            $this->dropIfExists($row->name);
            $row->forceFill(['status' => ExtraDatabase::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()])->save();
        } finally {
            if ($row->upload_path !== null) {
                @unlink($this->uploadsDir().'/'.$row->upload_path);
                $row->forceFill(['upload_path' => null])->save();
            }
        }
    }

    /** A backup that can be restored: finished, a database dump (whole or picked tables), not one school's .zip export. */
    public function assertRestorable(Backup $backup): void
    {
        if ($backup->status !== Backup::STATUS_OK || $backup->path === null) {
            throw ValidationException::withMessages(['backup_id' => __('Pick a backup that finished.')]);
        }
        if ($backup->format === Backup::FORMAT_SCHOOL) {
            throw ValidationException::withMessages(['backup_id' => __('A school export (.zip) cannot be restored as a database.')]);
        }
        if ($this->backupPath($backup) === null) {
            throw ValidationException::withMessages(['backup_id' => __('That backup file is no longer on the server.')]);
        }
    }

    /**
     * Keeps an uploaded file until its restore runs; refuses one that is not
     * a pg_dump custom-format file (they start with "PGDMP"). Returns its
     * name in the uploads folder.
     */
    public function keepUpload(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $magic = $handle ? (string) fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== 'PGDMP') {
            throw ValidationException::withMessages(['file' => __('That is not a database backup file (.dump made by a backup here or by pg_dump -Fc).')]);
        }

        File::ensureDirectoryExists($this->uploadsDir());
        $name = now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.dump';
        $file->move($this->uploadsDir(), $name);

        return $name;
    }

    private function backupFile(ExtraDatabase $row): string
    {
        $backup = Backup::query()->find($row->backup_id) ?? throw new \RuntimeException('The backup was deleted before it could be restored.');

        return $this->backupPath($backup) ?? throw new \RuntimeException("The file {$backup->path} is no longer on the server.");
    }

    /** The backup's file on the backups disk, else on a local second-place disk. */
    private function backupPath(Backup $backup): ?string
    {
        foreach (array_filter([config('platform.backup_disk'), config('platform.backup_copy_disk')]) as $disk) {
            $storage = Storage::disk($disk);
            if ($backup->path !== null && $storage->exists($backup->path) && config("filesystems.disks.{$disk}.driver") === 'local') {
                return $storage->path($backup->path);
            }
        }

        return null;
    }

    private function uploadFile(ExtraDatabase $row): string
    {
        $path = $this->uploadsDir().'/'.$row->upload_path;
        if ($row->upload_path === null || ! is_file($path)) {
            throw new \RuntimeException('The uploaded file is gone; upload it again.');
        }

        return $path;
    }

    /** Written by the web server, read (and emptied) by the queue worker — so not the backups folder, which only the worker may write. */
    public function uploadsDir(): string
    {
        return storage_path('app/restore-uploads');
    }

    /** Deletes the database and its record — refused while a school uses it or it is still being made. */
    public function drop(ExtraDatabase $row): void
    {
        if ($row->status === ExtraDatabase::STATUS_CREATING) {
            throw ValidationException::withMessages(['name' => __('It is still being made. Wait until it is ready or failed.')]);
        }
        if ($this->usedBySchool($row->name)) {
            throw ValidationException::withMessages(['name' => __('A school uses this database, so it cannot be deleted.')]);
        }

        $this->dropIfExists($row->name);
        $row->delete();
    }

    public function usedBySchool(string $name): bool
    {
        return SchoolPlacement::query()->where('database', $name)->exists()
            || collect($this->databases->all())->contains(fn (array $db) => $db['database'] === $name && $db['kind'] !== 'extra');
    }

    public function onServer(string $name): bool
    {
        return $this->server->exists($name);
    }

    public function dropIfExists(string $name): void
    {
        if ($this->onServer($name)) {
            $this->server->drop($name);
        }
    }

    /** Dump and restore (not CREATE … TEMPLATE, which needs the source to have no sessions — a live school always has some). */
    private function copy(string $source, string $into): void
    {
        $dir = storage_path('app/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/copy-'.$into.'-'.bin2hex(random_bytes(4)).'.dump';

        try {
            $this->pgDump->dump($source, $path);
            $this->pgDump->restoreInto($path, $into);
        } finally {
            @unlink($path);
        }
    }
}
