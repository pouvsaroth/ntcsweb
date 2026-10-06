<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\JobPostingRequest;
use App\Http\Resources\JobPostingResource;
use App\Http\Responses\ApiResponse;
use App\Models\JobPosting;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Job postings — where each job is advertised.
 */
final class JobPostingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobPosting::class);

        $postings = ApiQuery::for(JobPosting::query()->with('jobPosition'), $request)
            ->filterable(['job_position_id', 'channel', 'is_active'])
            ->sortable(['posted_on', 'expires_on', 'created_at'], default: '-posted_on')
            ->paginate();

        return ApiResponse::success(JobPostingResource::collection($postings));
    }

    public function store(JobPostingRequest $request): JsonResponse
    {
        $this->authorize('create', JobPosting::class);

        $posting = JobPosting::query()->create($request->validated());

        return ApiResponse::created(new JobPostingResource($posting->load('jobPosition')));
    }

    public function show(JobPosting $jobPosting): JsonResponse
    {
        $this->authorize('view', $jobPosting);

        return ApiResponse::success(new JobPostingResource($jobPosting->load('jobPosition')));
    }

    public function update(JobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        $this->authorize('update', $jobPosting);

        $jobPosting->update($request->validated());

        return ApiResponse::success(new JobPostingResource($jobPosting->load('jobPosition')));
    }

    public function destroy(JobPosting $jobPosting): JsonResponse
    {
        $this->authorize('delete', $jobPosting);

        $jobPosting->delete();

        return ApiResponse::noContent();
    }
}
