<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ClassSchedule;
use App\Models\Position;
use App\Models\Staff;
use App\Models\User;
use App\Services\Academic\TimetableService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academic > Timetable > Schedule changes (docs/academics/timetable.md): a
 * teacher asks to move one of their weekly times (day/time, room or teacher);
 * someone who manages the timetable approves (the timetable changes) or
 * rejects it. The asker can cancel while it waits.
 */
final class ScheduleChangeController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(Permissions::TIMETABLE_MANAGE, Permissions::TIMETABLE_REQUEST), 403);
        $manager = $user->hasPermission(Permissions::TIMETABLE_MANAGE);

        $rows = DB::connection('tenant')->table('schedule_change_requests as r')
            ->join('class_schedules as s', 's.id', '=', 'r.class_schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->leftJoin('classrooms as nr', 'nr.id', '=', 'r.new_classroom_id')
            ->leftJoin('staff as ns', 'ns.id', '=', 'r.new_staff_id')
            ->when(! $manager, fn ($q) => $q->where('r.requested_by', $user->getKey()))
            ->when($request->filled('status'), fn ($q) => $q->where('r.status', (string) $request->input('status')))
            ->orderByRaw("case r.status when 'pending' then 0 else 1 end")->orderByDesc('r.created_at')
            ->limit(200)
            ->get([
                'r.*', 'c.name as class', 's.day_of_week', 's.start_time', 's.end_time',
                'nr.name as new_room', DB::raw("trim(coalesce(ns.first_name, '') || ' ' || coalesce(ns.last_name, '')) as new_teacher"),
            ]);
        $names = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by'))->filter()->unique())->pluck('name', 'id');

        return ApiResponse::success($rows->map(fn ($r) => [
            'id' => $r->id,
            'class_schedule_id' => $r->class_schedule_id,
            'class' => $r->class,
            'from' => ['day_of_week' => (int) $r->day_of_week, 'start_time' => substr((string) $r->start_time, 0, 5), 'end_time' => substr((string) $r->end_time, 0, 5)],
            'to' => ['day_of_week' => (int) $r->new_day_of_week, 'start_time' => substr((string) $r->new_start_time, 0, 5), 'end_time' => substr((string) $r->new_end_time, 0, 5)],
            'new_room' => $r->new_room,
            'new_teacher' => trim((string) $r->new_teacher) ?: null,
            'reason' => $r->reason,
            'status' => $r->status,
            'requested_by' => $names[$r->requested_by] ?? null,
            'mine' => (int) $r->requested_by === (int) $user->getKey(),
            'created_at' => substr((string) $r->created_at, 0, 10),
            'decided_by' => $r->decided_by ? ($names[$r->decided_by] ?? null) : null,
            'decision_note' => $r->decision_note,
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(Permissions::TIMETABLE_REQUEST, Permissions::TIMETABLE_MANAGE), 403);
        $data = $request->validate([
            'class_schedule_id' => ['required', 'integer', Rule::exists('tenant.class_schedules', 'id')],
            'new_day_of_week' => ['required', 'integer', 'between:1,7'],
            'new_start_time' => ['required', 'date_format:H:i'],
            'new_end_time' => ['required', 'date_format:H:i', 'after:new_start_time'],
            'new_classroom_id' => ['nullable', 'integer', Rule::exists('tenant.classrooms', 'id')->whereNull('deleted_at')],
            'new_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // A teacher asks only for the times they teach.
        if (! $user->hasPermission(Permissions::TIMETABLE_MANAGE)) {
            $staffId = Staff::query()->where('user_id', $user->getKey())->value('id');
            $slot = $this->timetable->slots(fn ($q) => $q->whereKey($data['class_schedule_id']))->first();
            if ($staffId === null || $slot === null || ! in_array((int) $staffId, array_column($slot['teachers'], 'id'), true)) {
                throw ValidationException::withMessages(['class_schedule_id' => __('You can ask only for the times you teach.')]);
            }
        }

        $pending = DB::connection('tenant')->table('schedule_change_requests')
            ->where('class_schedule_id', $data['class_schedule_id'])->where('status', 'pending')->exists();
        if ($pending) {
            throw ValidationException::withMessages(['class_schedule_id' => __('A change for this time is already waiting.')]);
        }

        $id = DB::connection('tenant')->table('schedule_change_requests')->insertGetId($data + [
            'requested_by' => $user->getKey(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ApiResponse::created(['id' => $id]);
    }

    /** Approve: the weekly time moves; the answer lists any conflict it now has. */
    public function approve(Request $request, int $changeRequest): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TIMETABLE_MANAGE), 403);
        $r = $this->pending($changeRequest);
        /** @var ClassSchedule $slot */
        $slot = ClassSchedule::query()->findOrFail($r->class_schedule_id);

        $taken = ClassSchedule::query()->where('class_id', $slot->class_id)->where('day_of_week', $r->new_day_of_week)
            ->where('start_time', $r->new_start_time)->whereKeyNot($slot->getKey())->exists();
        if ($taken) {
            throw ValidationException::withMessages(['request' => __('The class already has a time starting then on that day.')]);
        }

        DB::connection('tenant')->transaction(function () use ($slot, $r, $request) {
            $changes = ['day_of_week' => $r->new_day_of_week, 'start_time' => $r->new_start_time, 'end_time' => $r->new_end_time];
            if ($r->new_classroom_id !== null) {
                $changes['classroom_id'] = $r->new_classroom_id;
            }
            if ($r->new_staff_id !== null) {
                $changes['staff_id'] = $r->new_staff_id;
            }
            $slot->update($changes);
            $this->decide((int) $r->id, 'approved', $request);
        });

        $all = $this->timetable->slots();

        return ApiResponse::success([
            'status' => 'approved',
            'conflicts' => array_values(array_filter($this->timetable->conflicts($all), fn ($c) => in_array($slot->id, array_column($c['slots'], 'id'), true))),
        ]);
    }

    public function reject(Request $request, int $changeRequest): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TIMETABLE_MANAGE), 403);
        $this->pending($changeRequest);
        $this->decide($changeRequest, 'rejected', $request);

        return ApiResponse::success(['status' => 'rejected']);
    }

    public function cancel(Request $request, int $changeRequest): JsonResponse
    {
        $r = $this->pending($changeRequest);
        abort_unless((int) $r->requested_by === (int) $request->user()->getKey(), 403);
        $this->decide($changeRequest, 'cancelled', $request);

        return ApiResponse::success(['status' => 'cancelled']);
    }

    private function pending(int $id): object
    {
        $r = DB::connection('tenant')->table('schedule_change_requests')->where('id', $id)->first();
        abort_if($r === null, 404);
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['request' => __('This request was already decided.')]);
        }

        return $r;
    }

    private function decide(int $id, string $status, Request $request): void
    {
        DB::connection('tenant')->table('schedule_change_requests')->where('id', $id)->update([
            'status' => $status,
            'decided_by' => $request->user()->getKey(),
            'decided_at' => now(),
            'decision_note' => $request->input('note'),
            'updated_at' => now(),
        ]);
    }
}
