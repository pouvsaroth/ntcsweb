<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\DataOps\DatabaseMaker;
use App\Platform\DataOps\DatabaseSwapper;
use App\Platform\Models\Backup;
use App\Platform\Models\DatabaseSwap;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data ops > Databases, "Other" databases: make one (empty, a copy of the
 * school template, or a copy of another database), restore a backup or an
 * uploaded file into a new one, put one in place of a school's database,
 * and delete one no school uses. Listed with the rest (DataOpsController::databases). Needs
 * platform.data.databases.
 */
final class ExtraDatabaseController extends Controller
{
    /** Same as the server's upload limit (docker/php/uploads.ini: 50M). */
    public const MAX_UPLOAD_KB = 50 * 1024;

    public function __construct(
        private readonly DatabaseMaker $maker,
        private readonly PlatformAudit $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'source' => ['required', Rule::in([ExtraDatabase::SOURCE_EMPTY, ExtraDatabase::SOURCE_TEMPLATE, ExtraDatabase::SOURCE_COPY])],
            'source_database' => ['required_if:source,copy', 'nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $name = strtolower(trim($data['name']));

        $this->maker->assertFreeName($name);
        $source = $data['source'] === ExtraDatabase::SOURCE_COPY ? (string) $data['source_database'] : null;
        if ($source !== null) {
            $this->maker->assertCopySource($source);
        }

        $row = $this->maker->queue($name, $data['source'], $source, $request->user('staff'), $data['note'] ?? null);
        $this->audit->record('database.created', $row, new: array_filter(['name' => $name, 'source' => $data['source'], 'source_database' => $source]));

        return ApiResponse::created(['id' => $row->id, 'name' => $row->name, 'status' => $row->status]);
    }

    /**
     * Restore into a NEW database: a good backup from the list, or an
     * uploaded .dump file (multipart `file`). Nothing live is touched.
     */
    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'source' => ['required', Rule::in([ExtraDatabase::SOURCE_BACKUP, ExtraDatabase::SOURCE_UPLOAD])],
            'backup_id' => ['required_if:source,backup', 'nullable', 'integer', Rule::exists(Backup::class, 'id')],
            'file' => ['required_if:source,upload', 'nullable', 'file', 'max:'.self::MAX_UPLOAD_KB],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $name = strtolower(trim($data['name']));
        $this->maker->assertFreeName($name);

        $extra = [];
        $from = null;
        if ($data['source'] === ExtraDatabase::SOURCE_BACKUP) {
            $backup = Backup::query()->findOrFail($data['backup_id']);
            $this->maker->assertRestorable($backup);
            $extra['backup_id'] = $backup->id;
            $from = $backup->database;
        } else {
            $file = $request->file('file');
            $extra['upload_name'] = mb_substr($file->getClientOriginalName(), 0, 255);
            $extra['upload_path'] = $this->maker->keepUpload($file);
        }

        $row = $this->maker->queue($name, $data['source'], $from, $request->user('staff'), $data['note'] ?? null, $extra);
        $this->audit->record('database.restored', $row, new: array_filter([
            'name' => $name, 'backup_id' => $extra['backup_id'] ?? null, 'from' => $from, 'file' => $extra['upload_name'] ?? null,
        ]));

        return ApiResponse::created(['id' => $row->id, 'name' => $row->name, 'status' => $row->status]);
    }

    /**
     * Use for a school: put this database in place of the school's own
     * database (DatabaseSwapper). The request must repeat the school's
     * database name.
     */
    public function useForSchool(Request $request, ExtraDatabase $database, DatabaseSwapper $swapper): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists(Tenant::class, 'id')],
            'confirm_name' => ['required', 'string'],
        ]);
        $tenant = Tenant::query()->findOrFail($data['tenant_id']);
        if ($data['confirm_name'] !== SchoolPlacement::of($tenant)->database) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the school database name exactly.')]);
        }

        $swap = $swapper->start($database, $tenant, $request->user('staff'));

        return ApiResponse::created($this->swapRow($swap->load(['tenant:id,name,slug', 'staff:id,name'])));
    }

    /** The latest swaps, newest first, with their steps. */
    public function swaps(): JsonResponse
    {
        $swaps = DatabaseSwap::query()->with(['tenant:id,name,slug', 'staff:id,name'])->latest('id')->limit(20)->get();

        return ApiResponse::success($swaps->map(fn (DatabaseSwap $s) => $this->swapRow($s))->all());
    }

    /** @return array<string, mixed> */
    private function swapRow(DatabaseSwap $swap): array
    {
        return [
            'id' => $swap->id,
            'school' => $swap->tenant ? ['id' => $swap->tenant->id, 'name' => $swap->tenant->name, 'slug' => $swap->tenant->slug] : null,
            'live_database' => $swap->live_database,
            'restored_database' => $swap->restored_database,
            'kept_as' => $swap->kept_as,
            'backup_id' => $swap->backup_id,
            'status' => $swap->status,
            'steps' => $swap->steps ?? [],
            'error' => $swap->error,
            'staff' => $swap->staff?->name,
            'created_at' => $swap->created_at?->toIso8601String(),
            'finished_at' => $swap->finished_at?->toIso8601String(),
        ];
    }

    /** Deletes it — the request must repeat its name. */
    public function destroy(Request $request, ExtraDatabase $database): JsonResponse
    {
        $typed = (string) $request->validate(['confirm_name' => ['required', 'string']])['confirm_name'];
        if ($typed !== $database->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the database name exactly to delete it.')]);
        }

        $this->maker->drop($database);
        $this->audit->record('database.dropped', $database, ['name' => $database->name, 'source' => $database->source]);

        return ApiResponse::noContent();
    }
}
