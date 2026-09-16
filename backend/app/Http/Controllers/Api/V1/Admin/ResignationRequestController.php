<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectResignationRequestRequest;
use App\Http\Resources\ResignationRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ResignationRequest;
use App\Services\Academic\ResignationRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResignationRequestController extends Controller
{
    public function __construct(
        private readonly ResignationRequestService $resignationRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ResignationRequest::class);

        $query = ResignationRequest::query()->with(['staff.position', 'decidedBy']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'staff_id'])
            ->sortable(['resignation_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ResignationRequestResource::collection($requests));
    }

    public function show(ResignationRequest $resignationRequest): JsonResponse
    {
        $this->authorize('view', $resignationRequest);

        return ApiResponse::success(new ResignationRequestResource(
            $resignationRequest->load(['staff.position', 'decidedBy'])
        ));
    }

    public function approve(ResignationRequest $resignationRequest, Request $request): JsonResponse
    {
        $this->authorize('approve', $resignationRequest);

        $resignationRequest = $this->resignationRequests->approve($resignationRequest, $request->user());

        return ApiResponse::success(new ResignationRequestResource($resignationRequest->load(['staff.position', 'decidedBy'])));
    }

    public function reject(RejectResignationRequestRequest $request, ResignationRequest $resignationRequest): JsonResponse
    {
        $resignationRequest = $this->resignationRequests->reject($resignationRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ResignationRequestResource($resignationRequest->load(['staff.position', 'decidedBy'])));
    }
}
