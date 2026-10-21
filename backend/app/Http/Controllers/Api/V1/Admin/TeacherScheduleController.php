<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherSubstitution;
use App\Models\TeacherUnavailability;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Teacher > Availability and Substitute (docs/hrm/teachers.md).
 *
 * Availability: the hours a teacher cannot teach — every week on a day, or
 * on given dates — next to their approved leave (Leave Management), with the
 * class times that clash with a weekly slot.
 *
 * Substitute: who covers a class on a day; the candidates are teachers not on
 * leave, not unavailable and not teaching another class at that time.
 */
final class TeacherScheduleController extends Controller
{
    private const LIVE_CLASS = [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE];

    // --- Availability -------------------------------------------------------------

    public function availability(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);
        $request->validate(['staff_id' => ['required', 'integer']]);
        $staffId = $request->integer('staff_id');

        $slots = TeacherUnavailability::query()->where('staff_id', $staffId)
            ->orderBy('kind', 'desc')->orderBy('day_of_week')->orderBy('from_date')->orderBy('start_time')->get();

        $leave = LeaveRequest::query()->where('staff_id', $staffId)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('to_date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('from_date')->get()
            ->map(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'from_date' => Carbon::parse($l->from_date)->toDateString(),
                'to_date' => Carbon::parse($l->to_date)->toDateString(),
                'days' => $l->days !== null ? (float) $l->days : null,
                'reason' => $l->reason,
            ])->values();

        // Class times this teacher has that fall in a weekly slot.
        $schedules = $this->classTimes([$staffId]);
        $clashes = [];
        foreach ($slots->where('kind', TeacherUnavailability::WEEKLY) as $slot) {
            foreach ($schedules as $s) {
                if ((int) $s->day_of_week === (int) $slot->day_of_week && $this->overlaps($slot->start_time, $slot->end_time, $s->start_time, $s->end_time)) {
                    $clashes[] = ['slot_id' => $slot->id, 'class' => $s->name, 'day_of_week' => (int) $s->day_of_week, 'start_time' => substr((string) $s->start_time, 0, 5), 'end_time' => substr((string) $s->end_time, 0, 5)];
                }
            }
        }

        return ApiResponse::success([
            'unavailable' => $slots->map(fn (TeacherUnavailability $u) => $this->slot($u))->values(),
            'leave' => $leave,
            'clashes' => $clashes,
        ]);
    }

    public function storeUnavailability(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);

        return ApiResponse::created($this->slot(TeacherUnavailability::query()->create($this->validatedSlot($request))));
    }

    public function updateUnavailability(Request $request, TeacherUnavailability $unavailability): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $unavailability->update($this->validatedSlot($request));

        return ApiResponse::success($this->slot($unavailability));
    }

    public function destroyUnavailability(Request $request, TeacherUnavailability $unavailability): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $unavailability->delete();

        return ApiResponse::noContent();
    }

    // --- Substitute ---------------------------------------------------------------

    public function substitutions(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        $rows = TeacherSubstitution::query()->with(['schoolClass:id,name', 'absentStaff', 'substituteStaff', 'book:id,title'])
            ->whereDate('date', '>=', (string) $request->input('from'))->whereDate('date', '<=', (string) $request->input('to'))
            ->orderBy('date')->orderBy('start_time')->get();

        return ApiResponse::success($rows->map(fn (TeacherSubstitution $s) => $this->substitution($s))->values());
    }

    /**
     * For a class on a day (and time): its teachers, which of them are away
     * (approved leave, or unavailable then), and who could cover.
     */
    public function candidates(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_VIEW);
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ]);
        $date = Carbon::parse($data['date']);
        $dow = $date->dayOfWeekIso;

        // The class's own time that day, when none is given.
        $own = DB::connection('tenant')->table('class_schedules')->where('class_id', $data['class_id'])->where('day_of_week', $dow)->orderBy('start_time')->first();
        $start = $data['start_time'] ?? ($own ? substr((string) $own->start_time, 0, 5) : null);
        $end = $data['end_time'] ?? ($own ? substr((string) $own->end_time, 0, 5) : null);

        $teachers = Staff::query()->whereIn('position_id', Position::teacherPositionIds())->where('status', Staff::STATUS_ACTIVE)
            ->orderBy('first_name')->get();
        $ids = $teachers->modelKeys();
        $away = $this->awayOn($ids, $date, $start, $end);

        $classTeacherIds = DB::connection('tenant')->table('class_teachers')->where('class_id', $data['class_id'])->pluck('staff_id')->map(fn ($id) => (int) $id)->all();

        // Busy: teaching another class at that time (or already covering one).
        $busy = [];
        if ($start !== null && $end !== null) {
            foreach ($this->classTimes($ids) as $s) {
                if ((int) $s->class_id !== (int) $data['class_id'] && (int) $s->day_of_week === $dow && $this->overlaps($start, $end, $s->start_time, $s->end_time)) {
                    $busy[(int) $s->staff_id] = $s->name;
                }
            }
            foreach (TeacherSubstitution::query()->with('schoolClass:id,name')->whereDate('date', $date->toDateString())->get() as $sub) {
                if ($sub->start_time === null || $this->overlaps($start, $end, $sub->start_time, $sub->end_time)) {
                    $busy[$sub->substitute_staff_id] = $sub->schoolClass?->name;
                }
            }
        }

        $row = fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName(), 'employee_code' => $s->employee_code];

        return ApiResponse::success([
            'date' => $date->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'class_teachers' => $teachers->filter(fn (Staff $s) => in_array($s->id, $classTeacherIds, true))
                ->map(fn (Staff $s) => $row($s) + ['away' => $away[$s->id] ?? null])->values(),
            'available' => $teachers->filter(fn (Staff $s) => ! in_array($s->id, $classTeacherIds, true) && ! isset($away[$s->id]) && ! isset($busy[$s->id]))
                ->map($row)->values(),
            'not_available' => $teachers->filter(fn (Staff $s) => ! in_array($s->id, $classTeacherIds, true) && (isset($away[$s->id]) || isset($busy[$s->id])))
                ->map(fn (Staff $s) => $row($s) + ['why' => $away[$s->id] ?? 'busy', 'busy_class' => $busy[$s->id] ?? null])->values(),
        ]);
    }

    public function storeSubstitution(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $teacher = Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at');
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'absent_staff_id' => ['required', 'integer', $teacher],
            'substitute_staff_id' => ['required', 'integer', 'different:absent_staff_id', $teacher],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $same = TeacherSubstitution::query()->where('class_id', $data['class_id'])->whereDate('date', $data['date'])
            ->where('absent_staff_id', $data['absent_staff_id'])
            ->where(fn ($q) => isset($data['start_time']) ? $q->where('start_time', $data['start_time']) : $q->whereNull('start_time'))
            ->exists();
        if ($same) {
            throw ValidationException::withMessages(['class_id' => __('A substitute is already set for this class at this time.')]);
        }

        $sub = TeacherSubstitution::query()->create($data + ['created_by' => $request->user()->getKey()]);

        return ApiResponse::created($this->substitution($sub->load(['schoolClass:id,name', 'absentStaff', 'substituteStaff', 'book:id,title'])));
    }

    public function destroySubstitution(Request $request, TeacherSubstitution $substitution): JsonResponse
    {
        $this->can($request, Permissions::TEACHERS_MANAGE);
        $substitution->delete();

        return ApiResponse::noContent();
    }

    // --- Helpers -------------------------------------------------------------------

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    /**
     * Weekly class times of these teachers (upcoming and active classes).
     *
     * @param  list<int>  $staffIds
     * @return Collection<int, object>
     */
    private function classTimes(array $staffIds): Collection
    {
        return DB::connection('tenant')->table('class_teachers as ct')
            ->join('classes as c', 'c.id', '=', 'ct.class_id')
            ->join('class_schedules as s', 's.class_id', '=', 'c.id')
            ->whereIn('ct.staff_id', $staffIds)->whereNull('c.deleted_at')->whereIn('c.status', self::LIVE_CLASS)
            ->get(['ct.staff_id', 'c.id as class_id', 'c.name', 's.day_of_week', 's.start_time', 's.end_time']);
    }

    /**
     * Teachers away at that day/time: 'leave' (approved leave covers the day)
     * or 'unavailable' (a weekly slot or a dated one).
     *
     * @param  list<int>  $staffIds
     * @return array<int, string>
     */
    private function awayOn(array $staffIds, Carbon $date, ?string $start, ?string $end): array
    {
        $day = $date->toDateString();
        $away = [];

        LeaveRequest::query()->whereIn('staff_id', $staffIds)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)
            ->pluck('staff_id')->each(function ($id) use (&$away) {
                $away[(int) $id] = 'leave';
            });

        $slots = TeacherUnavailability::query()->whereIn('staff_id', $staffIds)->get();
        foreach ($slots as $u) {
            if (isset($away[$u->staff_id])) {
                continue;
            }
            $onDay = $u->kind === TeacherUnavailability::WEEKLY
                ? (int) $u->day_of_week === $date->dayOfWeekIso
                : ($u->from_date !== null && $u->from_date->toDateString() <= $day && ($u->to_date ?? $u->from_date)->toDateString() >= $day);
            // No time on the slot = all day; no time asked = any part of the day.
            $atTime = $u->start_time === null || $start === null || $end === null || $this->overlaps($start, $end, $u->start_time, $u->end_time);
            if ($onDay && $atTime) {
                $away[$u->staff_id] = 'unavailable';
            }
        }

        return $away;
    }

    private function overlaps(?string $aStart, ?string $aEnd, ?string $bStart, ?string $bEnd): bool
    {
        if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
            return true;
        }

        return substr($aStart, 0, 5) < substr($bEnd, 0, 5) && substr($bStart, 0, 5) < substr($aEnd, 0, 5);
    }

    /** @return array<string, mixed> */
    private function validatedSlot(Request $request): array
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'kind' => ['required', Rule::in([TeacherUnavailability::WEEKLY, TeacherUnavailability::DATES])],
            'day_of_week' => ['required_if:kind,weekly', 'nullable', 'integer', 'between:1,7'],
            'from_date' => ['required_if:kind,dates', 'nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['kind'] === TeacherUnavailability::WEEKLY) {
            $data['from_date'] = $data['to_date'] = null;
        } else {
            $data['day_of_week'] = null;
            $data['to_date'] ??= $data['from_date'];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function slot(TeacherUnavailability $u): array
    {
        return [
            'id' => $u->id,
            'staff_id' => $u->staff_id,
            'kind' => $u->kind,
            'day_of_week' => $u->day_of_week,
            'from_date' => $u->from_date?->toDateString(),
            'to_date' => $u->to_date?->toDateString(),
            'start_time' => $u->start_time ? substr((string) $u->start_time, 0, 5) : null,
            'end_time' => $u->end_time ? substr((string) $u->end_time, 0, 5) : null,
            'reason' => $u->reason,
        ];
    }

    /** @return array<string, mixed> */
    private function substitution(TeacherSubstitution $s): array
    {
        return [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'start_time' => $s->start_time ? substr((string) $s->start_time, 0, 5) : null,
            'end_time' => $s->end_time ? substr((string) $s->end_time, 0, 5) : null,
            'class_id' => $s->class_id,
            'class' => $s->schoolClass?->name,
            'absent_staff_id' => $s->absent_staff_id,
            'absent' => $s->absentStaff?->fullName(),
            'substitute_staff_id' => $s->substitute_staff_id,
            'substitute' => $s->substituteStaff?->fullName(),
            'subject' => $s->book?->title,
            'reason' => $s->reason,
        ];
    }
}
