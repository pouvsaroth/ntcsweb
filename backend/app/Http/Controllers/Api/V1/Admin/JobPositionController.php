<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\JobPositionRequest;
use App\Http\Resources\JobPositionResource;
use App\Http\Responses\ApiResponse;
use App\Models\JobPosition;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Job positions — the school's vacancies.
 */
final class JobPositionController extends Controller
{
    private const RELATIONS = ['manpowerRequest', 'department', 'position', 'branch'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobPosition::class);

        $positions = ApiQuery::for(JobPosition::query()->with(self::RELATIONS)->withCount('postings'), $request)
            ->searchable('title')
            ->filterable(['status', 'department_id', 'manpower_request_id'])
            ->sortable(['title', 'closes_on', 'created_at'], default: '-created_at')
            // Recruitment's job pickers list every job at once.
            ->maxPerPage(500)
            ->paginate();

        $this->markCareersPage($positions->getCollection());

        return ApiResponse::success(JobPositionResource::collection($positions));
    }

    public function store(JobPositionRequest $request): JsonResponse
    {
        $this->authorize('create', JobPosition::class);

        $position = JobPosition::query()->create([
            'opened_on' => now()->toDateString(),
            ...$request->validated(),
        ]);

        return ApiResponse::created(new JobPositionResource($position->load(self::RELATIONS)->loadCount('postings')));
    }

    public function show(JobPosition $jobPosition): JsonResponse
    {
        $this->authorize('view', $jobPosition);

        return ApiResponse::success(new JobPositionResource($jobPosition->load(self::RELATIONS)->loadCount('postings')));
    }

    public function update(JobPositionRequest $request, JobPosition $jobPosition): JsonResponse
    {
        $this->authorize('update', $jobPosition);

        $jobPosition->update($request->validated());

        return ApiResponse::success(new JobPositionResource($jobPosition->load(self::RELATIONS)->loadCount('postings')));
    }

    public function destroy(JobPosition $jobPosition): JsonResponse
    {
        $this->authorize('delete', $jobPosition);

        if ($jobPosition->postings()->exists()) {
            return ApiResponse::error('This job has postings and cannot be deleted. Close it instead.', 422);
        }

        $jobPosition->delete();

        return ApiResponse::noContent();
    }

    /**
     * @param  iterable<JobPosition>  $positions
     */
    private function markCareersPage(iterable $positions): void
    {
        $ids = collect($positions)->map(fn (JobPosition $position) => $position->getKey())->all();
        $listed = JobPosition::query()->whereKey($ids)->onCareersPage()->pluck('id')->all();

        foreach ($positions as $position) {
            $position->setAttribute('on_careers_page', in_array($position->id, $listed, true));
        }
    }
}
