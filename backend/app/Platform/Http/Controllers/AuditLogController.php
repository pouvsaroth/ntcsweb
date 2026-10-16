<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\Resources\AuditLogResource;
use App\Platform\Models\PlatformAuditLog;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What staff did. Read-only — there is no route that changes or removes a row. */
final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = PlatformAuditLog::query()
            ->with('staff:id,name')
            ->when($request->date('from'), fn ($q, $from) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('created_at', '<=', $to->endOfDay()));

        $logs = ApiQuery::for($query, $request)
            ->searchable('action')
            ->filterable(['action', 'staff_id', 'tenant_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(AuditLogResource::collection($logs));
    }
}
