<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\OfferLetterRequest;
use App\Http\Resources\OfferLetterResource;
use App\Http\Responses\ApiResponse;
use App\Models\OfferLetter;
use App\Models\Staff;
use App\Services\Recruitment\OfferLetterService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Recruitment > Offer letter.
 */
final class OfferLetterController extends Controller
{
    private const RELATIONS = ['applicant', 'jobPosition', 'department'];

    public function __construct(private readonly OfferLetterService $offers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OfferLetter::class);

        $offers = ApiQuery::for(OfferLetter::query()->with(self::RELATIONS), $request)
            ->searchable('position_title')
            ->filterable(['status', 'job_position_id', 'applicant_id'])
            ->sortable(['start_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(OfferLetterResource::collection($offers));
    }

    public function store(OfferLetterRequest $request): JsonResponse
    {
        $this->authorize('create', OfferLetter::class);

        $offer = $this->offers->create($request->safe()->except('status'), $request->user());

        return ApiResponse::created(new OfferLetterResource($offer->load(self::RELATIONS)));
    }

    public function show(OfferLetter $offerLetter): JsonResponse
    {
        $this->authorize('view', $offerLetter);

        return ApiResponse::success(new OfferLetterResource($offerLetter->load(self::RELATIONS)));
    }

    public function update(OfferLetterRequest $request, OfferLetter $offerLetter): JsonResponse
    {
        $this->authorize('update', $offerLetter);

        $fields = $request->safe()->except('status');
        if ($fields !== []) {
            // The terms are what the applicant answered — fixed once they have.
            abort_if(in_array($offerLetter->status, [OfferLetter::STATUS_ACCEPTED, OfferLetter::STATUS_DECLINED], true), 422, 'An answered offer can no longer be changed.');
            $offerLetter->update($fields);
        }

        if ($request->has('status') && $request->validated('status') !== $offerLetter->status) {
            $this->offers->changeStatus($offerLetter, $request->validated('status'));
        }

        return ApiResponse::success(new OfferLetterResource($offerLetter->fresh(self::RELATIONS)));
    }

    public function destroy(OfferLetter $offerLetter): JsonResponse
    {
        $this->authorize('delete', $offerLetter);

        abort_unless($offerLetter->status === OfferLetter::STATUS_DRAFT, 422, 'Only a draft offer can be deleted. Withdraw a sent one instead.');

        $offerLetter->delete();

        return ApiResponse::noContent();
    }

    /** The staff member this accepted offer became — see StaffForm.vue's "Hire". */
    public function hire(Request $request, OfferLetter $offerLetter): JsonResponse
    {
        $this->authorize('update', $offerLetter);
        abort_unless($request->user()->can('create', Staff::class), 403);

        $staffId = $request->validate(['staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')]])['staff_id'];

        $this->offers->hire($offerLetter, Staff::query()->findOrFail($staffId));

        return ApiResponse::success(new OfferLetterResource($offerLetter->fresh(self::RELATIONS)));
    }
}
