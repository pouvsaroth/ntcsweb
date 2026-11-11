<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\DataOps\PlatformDatabases;
use App\Platform\DataOps\TableBrowser;
use App\Platform\Jobs\ClearTable;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data ops > Tables: browse one table of one of our databases with
 * conditions, change one row (by its primary key), or clear the table after
 * looking at what points to it. Every change goes to the audit log.
 */
final class TableDataController extends Controller
{
    public function __construct(
        private readonly PlatformDatabases $databases,
        private readonly TableBrowser $browser,
        private readonly PlatformAudit $audit,
    ) {}

    public function rows(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->target(),
            'conditions' => ['sometimes', 'array', 'max:20'],
            'conditions.*.column' => ['required', 'string', 'max:63'],
            'conditions.*.operator' => ['required', Rule::in(TableBrowser::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'string', 'max:63'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $database = $this->known($data['database']);

        return ApiResponse::success($this->browser->rows(
            $database,
            $data['table'],
            array_values($data['conditions'] ?? []),
            $data['sort'] ?? null,
            $data['direction'] ?? 'asc',
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 50),
        ));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->target(),
            'key' => ['required', 'array', 'min:1'],
            'key.*' => ['nullable', 'string', 'max:1000'],
            'changes' => ['present', 'array'],
            'changes.*' => ['nullable', 'string', 'max:1000000'],
        ]);
        $database = $this->known($data['database']);

        $result = $this->browser->update($database, $data['table'], $data['key'], $data['changes']);

        if ($result['new'] !== []) {
            $this->audit->record(
                'data.row.updated',
                old: $result['old'],
                new: ['database' => $database, 'table' => $data['table'], 'key' => $data['key'], 'values' => $result['new']],
                tenantId: $this->tenantId($database),
            );
        }

        return ApiResponse::success(['row' => $result['row'], 'changed' => array_keys($result['new'])]);
    }

    public function relationships(Request $request): JsonResponse
    {
        $data = $request->validate($this->target());
        $database = $this->known($data['database']);

        $out = $this->browser->relationships($database, $data['table']);
        // The shared database holds many schools' rows in the same tables.
        $out['shared_schools'] = $database === config('tenancy.database.shared_database')
            ? count(collect($this->databases->all())->firstWhere('database', $database)['schools'] ?? [])
            : null;

        return ApiResponse::success($out);
    }

    /**
     * Clear all data: checked here (typed name, cascade when other tables
     * point to it), then done in the background — backup first, then
     * TRUNCATE. Answers with a token; GET clear/{token} tells how it went.
     */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->target(),
            'cascade' => ['boolean'],
            'restart_ids' => ['boolean'],
            'backup_first' => ['boolean'],
            // Typed by hand: the table's name, so a click alone never clears.
            'confirm' => ['required', 'string'],
        ]);
        $database = $this->known($data['database']);
        $table = $data['table'];
        $cascade = (bool) ($data['cascade'] ?? false);

        if ($data['confirm'] !== $table) {
            throw ValidationException::withMessages(['confirm' => __('Type the table name exactly to confirm.')]);
        }
        $relations = $this->browser->relationships($database, $table);
        if ($relations['cascade'] !== [] && ! $cascade) {
            throw ValidationException::withMessages(['cascade' => __('Other tables point to :table. Tick "also clear the tables that point to it" to clear them too.', ['table' => $table])]);
        }

        $token = (string) Str::uuid();
        ClearTable::put($token, ['status' => 'running']);
        ClearTable::dispatch(
            $token,
            $database,
            $table,
            $cascade,
            (bool) ($data['restart_ids'] ?? false),
            (bool) ($data['backup_first'] ?? true),
            $request->user('staff')?->getKey(),
        );

        return ApiResponse::success(['token' => $token, ...$this->clearState($token)]);
    }

    /**
     * Delete the table itself (DROP TABLE), right away. The typed name must
     * match; when other tables point to it, cascade removes those links.
     */
    public function drop(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->target(),
            'cascade' => ['boolean'],
            // Typed by hand: the table's name, so a click alone never deletes.
            'confirm' => ['required', 'string'],
        ]);
        $database = $this->known($data['database']);
        $table = $data['table'];

        if ($data['confirm'] !== $table) {
            throw ValidationException::withMessages(['confirm' => __('Type the table name exactly to confirm.')]);
        }

        $links = $this->browser->drop($database, $table, (bool) ($data['cascade'] ?? false));

        $this->audit->record('data.table.dropped', new: [
            'database' => $database,
            'table' => $table,
            'links_removed' => $links,
        ], tenantId: $this->tenantId($database));

        return ApiResponse::success(['table' => $table, 'links_removed' => $links]);
    }

    public function clearStatus(string $token): JsonResponse
    {
        return ApiResponse::success(['token' => $token, ...$this->clearState($token)]);
    }

    /** @return array<string, mixed> */
    private function clearState(string $token): array
    {
        return Cache::get(ClearTable::cacheKey($token)) ?? ['status' => 'unknown'];
    }

    /** @return array<string, list<mixed>> */
    private function target(): array
    {
        return [
            'database' => ['required', 'string', 'max:100'],
            'table' => ['required', 'string', 'max:63'],
        ];
    }

    private function known(string $database): string
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

    private function tenantId(string $database): ?int
    {
        return $this->databases->tenantFor($database)?->getKey();
    }
}
