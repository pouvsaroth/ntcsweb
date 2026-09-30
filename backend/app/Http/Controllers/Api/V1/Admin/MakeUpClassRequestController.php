<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectMakeUpClassRequestRequest;
use App\Http\Resources\MakeUpClassRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MakeUpClassRequest;
use App\Services\Academic\MakeUpClassRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MakeUpClassRequestController extends Controller
{
    public function __construct(
        private readonly MakeUpClassRequestService $makeUpClassRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MakeUpClassRequest::class);

        $query = MakeUpClassRequest::query()->with(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(MakeUpClassRequestResource::collection($requests));
    }

    public function show(MakeUpClassRequest $makeUpClassRequest): JsonResponse
    {
        $this->authorize('view', $makeUpClassRequest);

        return ApiResponse::success(new MakeUpClassRequestResource(
            $makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])
        ));
    }

    public function approve(MakeUpClassRequest $makeUpClassRequest, Request $request): JsonResponse
    {
        $this->authorize('approve', $makeUpClassRequest);

        $makeUpClassRequest = $this->makeUpClassRequests->approve($makeUpClassRequest, $request->user());

        return ApiResponse::success(new MakeUpClassRequestResource($makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])));
    }

    public function reject(RejectMakeUpClassRequestRequest $request, MakeUpClassRequest $makeUpClassRequest): JsonResponse
    {
        $makeUpClassRequest = $this->makeUpClassRequests->reject($makeUpClassRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new MakeUpClassRequestResource($makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])));
    }
}
