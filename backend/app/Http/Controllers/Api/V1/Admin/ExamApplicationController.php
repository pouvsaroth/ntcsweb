<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectExamApplicationRequest;
use App\Http\Resources\ExamApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExamApplication;
use App\Services\Academic\ExamApplicationService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExamApplicationController extends Controller
{
    public function __construct(
        private readonly ExamApplicationService $examApplications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExamApplication::class);

        $query = ExamApplication::query()->with('student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book', 'decidedBy');

        $applications = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id'])
            ->sortable(['exam_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ExamApplicationResource::collection($applications));
    }

    public function show(ExamApplication $examApplication): JsonResponse
    {
        $this->authorize('view', $examApplication);

        return ApiResponse::success(new ExamApplicationResource(
            $examApplication->load('student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book', 'decidedBy')
        ));
    }

    public function approve(ExamApplication $examApplication, Request $request): JsonResponse
    {
        $this->authorize('approve', $examApplication);

        $examApplication = $this->examApplications->approve($examApplication, $request->user());

        return ApiResponse::success(new ExamApplicationResource(
            $examApplication->load('student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book', 'decidedBy')
        ));
    }

    public function reject(RejectExamApplicationRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $examApplication = $this->examApplications->reject($examApplication, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ExamApplicationResource(
            $examApplication->load('student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book', 'decidedBy')
        ));
    }
}
