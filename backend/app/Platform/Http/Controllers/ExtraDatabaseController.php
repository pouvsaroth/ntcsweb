<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\DataOps\DatabaseMaker;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data ops > Databases, "Other" databases: make one (empty, a copy of the
 * school template, or a copy of another database) and delete one no school
 * uses. Listed with the rest (DataOpsController::databases). Needs
 * platform.data.databases.
 */
final class ExtraDatabaseController extends Controller
{
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
