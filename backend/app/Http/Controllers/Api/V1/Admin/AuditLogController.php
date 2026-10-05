<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit logs are written only by AuditLogger (directly, or automatically via
 * the Auditable trait), never through this controller, and no single entry
 * can be edited or deleted. `clear` is the one write: removing a whole date
 * range — see AuditLogPolicy's docblock.
 */
final class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with(['user', 'auditable']);

        // Range filtering isn't something ApiQuery's allow-listed exact/IN
        // `filter[x]=` shape covers, so it's applied directly on the builder
        // before handing off for search/filter/sort/paginate.
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        $logs = ApiQuery::for($query, $request)
            ->searchable('description', 'event')
            ->filterable(['user_id', 'action', 'module', 'auditable_type', 'auditable_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(AuditLogResource::collection($logs));
    }

    /**
     * Permanently deletes every log of the current school dated between
     * `date_from` and `date_to` (both inclusive, same whereDate() meaning as
     * index()'s filters), then writes one AUDIT_LOGS_CLEARED entry recording
     * who cleared which range — written after the delete, so it survives even
     * a range that ends today.
     */
    public function clear(Request $request): JsonResponse
    {
        $this->authorize('clear', AuditLog::class);

        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        // Through the model, not DB::table(), so BelongsToTenant scopes the
        // delete to this school only.
        $deleted = AuditLog::query()
            ->whereDate('created_at', '>=', $validated['date_from'])
            ->whereDate('created_at', '<=', $validated['date_to'])
            ->delete();

        $this->audit->log(
            AuditAction::AUDIT_LOGS_CLEARED,
            'Audit Logs',
            new: ['date_from' => $validated['date_from'], 'date_to' => $validated['date_to'], 'deleted' => $deleted],
            description: "Cleared {$deleted} audit log(s) from {$validated['date_from']} to {$validated['date_to']}",
        );

        return ApiResponse::success(['deleted' => $deleted], __(':count audit log(s) cleared.', ['count' => $deleted]));
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $this->authorize('view', $auditLog);

        return ApiResponse::success(new AuditLogResource($auditLog->load(['user', 'auditable'])));
    }
}
