<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyResignationRequestRequest;
use App\Http\Resources\ResignationRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ResignationRequest;
use App\Models\Staff;
use App\Services\Academic\ResignationRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self-service: "my resignation requests," not "all resignation requests."
 * Identity-gated through `$user->staff` — no permission is required or
 * checked here, same pattern as MyLeaveRequestController.
 */
final class MyResignationRequestController extends Controller
{
    public function __construct(
        private readonly ResignationRequestService $resignationRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        $query = ResignationRequest::query()->where('staff_id', $staff->id);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['resignation_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ResignationRequestResource::collection($requests));
    }

    public function store(StoreMyResignationRequestRequest $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        $resignationRequest = $this->resignationRequests->submit($staff, $request->validated());

        return ApiResponse::created(new ResignationRequestResource($resignationRequest->load('staff.position')));
    }

    /**
     * The signed-in staff member's own name/gender/position — used to
     * auto-fill the resignation form's read-only identity fields, rather
     * than making the submitter retype data the school already has.
     */
    public function profile(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);
        $staff->loadMissing('position');

        return ApiResponse::success([
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'gender' => $staff->gender,
            'position' => $staff->position?->name,
        ]);
    }

    private function staffOrFail(Request $request): Staff
    {
        $staff = $request->user()?->staff;

        if ($staff === null) {
            throw ValidationException::withMessages([
                'staff' => 'This account is not linked to a staff record.',
            ]);
        }

        return $staff;
    }
}
