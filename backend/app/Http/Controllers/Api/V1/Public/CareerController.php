<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyForJobRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\JobPosition;
use App\Services\Recruitment\ApplicantService;
use Illuminate\Http\JsonResponse;

/**
 * The public Careers page. Unauthenticated — gated only by `tenant.required`
 * on the route group, same as DownloadController. Lists the jobs
 * JobPosition::scopeOnCareersPage() allows, and nothing internal (no
 * manpower request, no postings on other channels).
 */
final class CareerController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = JobPosition::query()->onCareersPage()->with(['department', 'branch'])->orderByDesc('opened_on')->orderByDesc('id')->get();

        return ApiResponse::success($jobs->map(fn (JobPosition $job) => $this->summary($job))->values());
    }

    public function show(int $id): JsonResponse
    {
        $job = JobPosition::query()->onCareersPage()->with(['department', 'branch'])->find($id);
        abort_if($job === null, 404);

        return ApiResponse::success([
            ...$this->summary($job),
            'description' => $job->description,
            'requirements' => $job->requirements,
        ]);
    }

    /**
     * The Apply form: a new applicant for this job, with their CV. Rate
     * limited on the route; HR is notified (see ApplicantService::create()).
     */
    public function apply(ApplyForJobRequest $request, ApplicantService $applicants, int $id): JsonResponse
    {
        $job = JobPosition::query()->onCareersPage()->find($id);
        abort_if($job === null, 404);

        $applicants->create([
            ...$request->safe()->except('cv'),
            'job_position_id' => $job->id,
            'source' => Applicant::SOURCE_WEBSITE,
            'stage' => Applicant::STAGE_NEW,
        ], $request->file('cv'), null);

        return ApiResponse::success(message: __('Thank you — your application has been sent.'), status: 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(JobPosition $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'department' => $job->department?->name,
            'location' => $job->branch?->name,
            'headcount' => $job->headcount,
            'employment_type' => $job->employment_type,
            'salary_min' => $job->salary_min !== null ? (float) $job->salary_min : null,
            'salary_max' => $job->salary_max !== null ? (float) $job->salary_max : null,
            'salary_currency' => $job->salary_currency,
            'closes_on' => $job->closes_on?->toDateString(),
        ];
    }
}
