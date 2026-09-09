<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffStatusHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\StaffStatusHistory;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, cross-staff listing — every StaffStatusHistory row across every
 * staff member, for the "Staff Status History" admin page. Writing a new
 * entry happens exclusively through StaffController::changeStatus(); there
 * is deliberately no store/update/destroy here, same reasoning as
 * AuditLogController.
 */
final class StaffStatusHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::STAFF_VIEW), 403);

        $query = StaffStatusHistory::query()->with(['staff', 'changedBy']);

        // Range filtering isn't something ApiQuery's allow-listed exact/IN
        // `filter[x]=` shape covers, so it's applied directly on the builder
        // before handing off for filter/sort/paginate — same approach as
        // AuditLogController.
        if ($request->filled('date_from')) {
            $query->whereDate('effective_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('effective_date', '<=', $request->string('date_to')->toString());
        }

        $histories = ApiQuery::for($query, $request)
            ->filterable(['staff_id', 'to_status'])
            ->sortable(['created_at', 'effective_date'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StaffStatusHistoryResource::collection($histories));
    }
}
