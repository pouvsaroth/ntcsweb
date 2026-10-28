<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\InquiryRequest;
use App\Http\Resources\InquiryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Inquiry;
use App\Models\InquiryFollowUp;
use App\Models\Staff;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Front Office > Inquiry: record who asked, follow them up (each contact
 * kept as history, moving the next follow-up date on), and link the
 * student once they register.
 */
class InquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inquiry::class);

        $query = Inquiry::query()->with('assignedStaff')->withCount('followUps');
        if ($request->boolean('due')) {
            $query->due();
        }

        $inquiries = ApiQuery::for($query, $request)
            ->searchable('name', 'phone', 'interested_in')
            ->filterable(['status', 'source', 'assigned_staff_id'])
            ->sortable(['inquiry_date', 'name', 'next_follow_up_on', 'created_at'], default: '-inquiry_date')
            ->paginate();

        return ApiResponse::success(InquiryResource::collection($inquiries));
    }

    /** Counts for the cards above the list: by status, and how many are due for a follow-up today. */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Inquiry::class);

        $byStatus = Inquiry::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return ApiResponse::success([
            'total' => (int) $byStatus->sum(),
            'by_status' => collect(Inquiry::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
            'due' => Inquiry::query()->due()->count(),
            'this_month' => Inquiry::query()->whereBetween('inquiry_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
        ]);
    }

    /** Active staff, for "Assigned to" (front-desk users may not see the Staff list). */
    public function staffOptions(): JsonResponse
    {
        $this->authorize('viewAny', Inquiry::class);

        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code]);

        return ApiResponse::success($staff);
    }

    public function store(InquiryRequest $request): JsonResponse
    {
        $this->authorize('create', Inquiry::class);

        $inquiry = Inquiry::query()->create([...$request->validated(), 'created_by' => $request->user()?->id]);

        return ApiResponse::created(new InquiryResource($inquiry->load('assignedStaff', 'student')));
    }

    public function show(Inquiry $inquiry): JsonResponse
    {
        $this->authorize('view', $inquiry);

        return ApiResponse::success(new InquiryResource($inquiry->load('assignedStaff', 'student', 'followUps')));
    }

    public function update(InquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        $this->authorize('update', $inquiry);

        $inquiry->update($request->validated());

        return ApiResponse::success(new InquiryResource($inquiry->load('assignedStaff', 'student', 'followUps')));
    }

    public function destroy(Inquiry $inquiry): JsonResponse
    {
        $this->authorize('delete', $inquiry);

        $inquiry->delete();

        return ApiResponse::noContent();
    }

    /**
     * One contact with the inquirer. Its next date becomes the inquiry's
     * next follow-up; a New inquiry becomes Following up, and the status
     * can be changed in the same step (e.g. Not interested).
     */
    public function addFollowUp(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->authorize('update', $inquiry);

        $data = $request->validate([
            'followed_up_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(InquiryFollowUp::METHODS)],
            'note' => ['required', 'string', 'max:5000'],
            'next_follow_up_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:followed_up_on'],
            'status' => ['nullable', Rule::in(['following_up', 'not_interested'])],
        ]);

        DB::connection('tenant')->transaction(function () use ($request, $inquiry, $data) {
            $inquiry->followUps()->create([
                'followed_up_on' => $data['followed_up_on'],
                'method' => $data['method'],
                'note' => $data['note'],
                'next_follow_up_on' => $data['next_follow_up_on'] ?? null,
                'created_by' => $request->user()?->id,
                'created_by_name' => $request->user()?->name,
            ]);

            $status = $data['status'] ?? ($inquiry->status === 'new' ? 'following_up' : $inquiry->status);
            $inquiry->update([
                'status' => $status,
                'next_follow_up_on' => $status === 'not_interested' ? null : ($data['next_follow_up_on'] ?? null),
            ]);
        });

        return ApiResponse::created(new InquiryResource($inquiry->fresh()->load('assignedStaff', 'student', 'followUps')));
    }

    /** The inquirer registered: link the new student and close the inquiry as Enrolled. */
    public function enroll(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->authorize('update', $inquiry);

        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('tenant.students', 'id')->whereNull('deleted_at')],
        ]);

        $inquiry->update(['student_id' => $data['student_id'], 'status' => 'enrolled', 'next_follow_up_on' => null]);

        return ApiResponse::success(new InquiryResource($inquiry->load('assignedStaff', 'student', 'followUps')));
    }
}
