<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ApplicantDocumentRequest;
use App\Http\Resources\ApplicantDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Services\Recruitment\ApplicantService;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRM > Recruitment > CV/resume — every applicant's files, and the only
 * way to download one (they're on the private disk).
 */
final class ApplicantDocumentController extends Controller
{
    public function __construct(private readonly ApplicantService $applicants) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApplicantDocument::class);

        $query = ApplicantDocument::query()->with(['applicant.jobPosition', 'uploadedBy']);

        // The applicant's job — a column of the applicant, not the document.
        if ($request->filled('job_position_id')) {
            $query->whereHas('applicant', fn (Builder $applicant) => $applicant->where('job_position_id', $request->integer('job_position_id')));
        }

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('LOWER(original_name) LIKE ?', [$term])
                ->orWhereHas('applicant', fn (Builder $applicant) => $applicant
                    ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])));
        }

        $documents = ApiQuery::for($query, $request)
            ->filterable(['type', 'applicant_id'])
            ->sortable(['created_at', 'size'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ApplicantDocumentResource::collection($documents));
    }

    public function store(ApplicantDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', ApplicantDocument::class);

        $applicant = Applicant::query()->findOrFail($request->validated('applicant_id'));
        $document = $this->applicants->attach($applicant, $request->file('file'), $request->validated('type'), $request->user());

        return ApiResponse::created(new ApplicantDocumentResource($document->load(['applicant.jobPosition', 'uploadedBy'])));
    }

    public function download(ApplicantDocument $applicantDocument): StreamedResponse
    {
        $this->authorize('view', $applicantDocument);

        abort_unless(Storage::disk('local')->exists($applicantDocument->file_path), 404);

        return Storage::disk('local')->download($applicantDocument->file_path, $applicantDocument->original_name);
    }

    public function destroy(ApplicantDocument $applicantDocument): JsonResponse
    {
        $this->authorize('delete', $applicantDocument);

        $applicantDocument->delete();

        return ApiResponse::noContent();
    }
}
