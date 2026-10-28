<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\MeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Meeting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Front Office > Meetings: schedule a meeting (date, time, place, agenda),
 * invite staff (they are notified) and outside guests, then keep the
 * meeting note and mark it done or cancelled.
 */
class MeetingController extends Controller
{
    private const LINK = '/admin/front-office/meetings';

    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $query = Meeting::query()->with('organizer')->withCount('participants');
        $today = now()->toDateString();
        $when = $request->query('when');
        if ($when === 'upcoming') {
            $query->where('meeting_date', '>=', $today)->where('status', 'scheduled');
        } elseif ($when === 'past') {
            $query->where(fn ($q) => $q->where('meeting_date', '<', $today)->orWhere('status', '!=', 'scheduled'));
        }
        if ($request->boolean('mine')) {
            $staffId = $this->staffIdOf($request->user());
            $query->where(fn ($q) => $q->where('organizer_staff_id', $staffId)
                ->orWhereHas('participants', fn ($p) => $p->where('staff.id', $staffId)));
        }

        // Upcoming reads soonest first; everything else newest first. Same day: by time.
        if (! $request->filled('sort')) {
            $request->merge(['sort' => $when === 'upcoming' ? 'meeting_date,start_time' : '-meeting_date,-start_time']);
        }
        $meetings = ApiQuery::for($query, $request)
            ->searchable('title', 'location')
            ->filterable(['status', 'organizer_staff_id'])
            ->sortable(['meeting_date', 'start_time', 'title', 'created_at'])
            ->paginate();

        return ApiResponse::success(MeetingResource::collection($meetings));
    }

    /** Counts for the cards above the list. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $today = now()->toDateString();

        return ApiResponse::success([
            'today' => Meeting::query()->where('status', 'scheduled')->whereDate('meeting_date', $today)->count(),
            'upcoming' => Meeting::query()->where('status', 'scheduled')->where('meeting_date', '>=', $today)->count(),
            'this_month' => Meeting::query()->whereBetween('meeting_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'completed' => Meeting::query()->where('status', 'completed')->count(),
        ]);
    }

    /** Active staff, for organizer and participants (front-desk users may not see the Staff list). */
    public function staffOptions(): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code]);

        return ApiResponse::success($staff);
    }

    public function store(MeetingRequest $request): JsonResponse
    {
        $this->authorize('create', Meeting::class);

        $data = $request->validated();
        $participantIds = array_map('intval', $data['participant_ids'] ?? []);

        $meeting = DB::connection('tenant')->transaction(function () use ($request, $data, $participantIds) {
            $meeting = Meeting::query()->create([...Arr::except($data, ['participant_ids', 'status']), 'created_by' => $request->user()?->id]);
            $meeting->participants()->sync($participantIds);

            return $meeting;
        });

        $this->notifyStaff($participantIds, NotificationType::MEETING_INVITED, $meeting, $request->user());

        return ApiResponse::created(new MeetingResource($meeting->load('organizer', 'participants')));
    }

    public function show(Meeting $meeting): JsonResponse
    {
        $this->authorize('view', $meeting);

        return ApiResponse::success(new MeetingResource($meeting->load('organizer', 'participants')));
    }

    /**
     * Edit details, the meeting note or the status. Newly added staff are
     * invited; staff already in it hear when the date, time or place
     * changes, or when it is cancelled.
     */
    public function update(MeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $data = $request->validated();
        $before = $meeting->participants()->pluck('staff.id')->map(fn ($id) => (int) $id)->all();

        DB::connection('tenant')->transaction(function () use ($meeting, $data) {
            $meeting->update(Arr::except($data, ['participant_ids']));
            if (array_key_exists('participant_ids', $data)) {
                $meeting->participants()->sync(array_map('intval', $data['participant_ids']));
            }
        });

        $after = array_key_exists('participant_ids', $data) ? array_map('intval', $data['participant_ids']) : $before;
        $added = array_values(array_diff($after, $before));
        $kept = array_values(array_intersect($after, $before));

        $this->notifyStaff($added, NotificationType::MEETING_INVITED, $meeting, $request->user());
        if ($meeting->wasChanged('status') && $meeting->status === 'cancelled') {
            $this->notifyStaff($kept, NotificationType::MEETING_CANCELLED, $meeting, $request->user());
        } elseif ($meeting->status === 'scheduled' && $meeting->wasChanged(['meeting_date', 'start_time', 'end_time', 'location'])) {
            $this->notifyStaff($kept, NotificationType::MEETING_UPDATED, $meeting, $request->user());
        }

        return ApiResponse::success(new MeetingResource($meeting->load('organizer', 'participants')));
    }

    public function destroy(Meeting $meeting): JsonResponse
    {
        $this->authorize('delete', $meeting);

        $meeting->delete();

        return ApiResponse::noContent();
    }

    /** @param  list<int>  $staffIds */
    private function notifyStaff(array $staffIds, string $type, Meeting $meeting, ?User $actor): void
    {
        if ($staffIds === []) {
            return;
        }

        $users = Staff::query()->whereIn('id', $staffIds)->with('user')->get()
            ->pluck('user')->filter()
            ->reject(fn (User $u) => $actor !== null && $u->getKey() === $actor->getKey());

        $this->notifications->notifyMany($users, $type, [
            'meeting_id' => $meeting->id,
            'title' => $meeting->title,
            'date' => $meeting->meeting_date->toDateString(),
            'time' => substr((string) $meeting->start_time, 0, 5),
            'location' => $meeting->location,
        ], link: self::LINK);
    }

    private function staffIdOf(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        return Staff::query()->where('user_id', $user->getKey())->value('id');
    }
}
