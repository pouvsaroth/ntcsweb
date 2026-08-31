<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only by design — see AuditLogPolicy's docblock. There is deliberately
 * no store/update/destroy here: audit logs are historical records, written
 * only by AuditLogger (directly, or automatically via the Auditable trait),
 * never through this controller.
 */
final class AuditLogController extends Controller
{
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

    public function show(AuditLog $auditLog): JsonResponse
    {
        $this->authorize('view', $auditLog);

        return ApiResponse::success(new AuditLogResource($auditLog->load(['user', 'auditable'])));
    }
}
