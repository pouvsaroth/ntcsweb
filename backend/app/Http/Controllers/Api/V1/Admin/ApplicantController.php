<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ApplicantRequest;
use App\Http\Resources\ApplicantResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Services\Recruitment\ApplicantService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Applicant management.
 */
final class ApplicantController extends Controller
{
    public function __construct(private readonly ApplicantService $applicants) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $applicants = ApiQuery::for(Applicant::query()->with('jobPosition')->withCount('documents'), $request)
            ->searchable('first_name', 'last_name', 'phone', 'email')
            ->filterable(['job_position_id', 'stage', 'source'])
            ->sortable(['first_name', 'created_at'], default: '-created_at')
            // The CV/resume tab's upload picker lists every applicant at once.
            ->maxPerPage(500)
            ->paginate();

        return ApiResponse::success(ApplicantResource::collection($applicants));
    }

    public function store(ApplicantRequest $request): JsonResponse
    {
        $this->authorize('create', Applicant::class);

        $applicant = $this->applicants->create($request->safe()->except('cv'), $request->file('cv'), $request->user());

        return ApiResponse::created(new ApplicantResource($applicant->load(['jobPosition', 'documents.uploadedBy'])));
    }

    public function show(Applicant $applicant): JsonResponse
    {
        $this->authorize('view', $applicant);

        return ApiResponse::success(new ApplicantResource($applicant->load(['jobPosition', 'documents.uploadedBy'])));
    }

    public function update(ApplicantRequest $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('update', $applicant);

        // Hired only ever happens through Hire on an accepted offer (see
        // OfferLetterService::hire()), so every Hired applicant has a staff record.
        if ($request->validated('stage') === 'hired' && $applicant->stage !== 'hired') {
            return ApiResponse::error('Use Hire on the accepted offer letter to hire someone.', 422);
        }

        $applicant->update($request->safe()->except('cv'));

        return ApiResponse::success(new ApplicantResource($applicant->load(['jobPosition', 'documents.uploadedBy'])));
    }

    public function destroy(Applicant $applicant): JsonResponse
    {
        $this->authorize('delete', $applicant);

        $applicant->delete();

        return ApiResponse::noContent();
    }
}
