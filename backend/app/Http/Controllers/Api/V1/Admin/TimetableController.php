<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherSubstitution;
use App\Services\Academic\TimetableService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Academic > Timetable (docs/academics/timetable.md): class, teacher and room
 * timetables, hours per subject, conflicts, and editing a class's weekly times
 * (the same times as the class form) with their subject, teacher and room.
 */
final class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    public function options(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);

        return ApiResponse::success([
            'classes' => SchoolClass::query()->with(['academicYear:id,name', 'classroom:id,name'])->whereIn('status', TimetableService::LIVE)->orderBy('name')->get()
                ->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name, 'academic_year' => $c->academicYear?->name, 'room' => $c->classroom?->name])->values(),
            'teachers' => Staff::query()->whereIn('position_id', Position::teacherPositionIds())->where('status', Staff::STATUS_ACTIVE)->orderBy('first_name')->get()
                ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->fullName()])->values(),
            'rooms' => Classroom::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'subjects' => Book::query()->active()->orderBy('title')->get(['id', 'title'])->map(fn ($b) => ['id' => $b->id, 'title' => $b->title])->values(),
            'me' => $this->myStaffId($request),
        ]);
    }

    public function forClass(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);
        $request->validate(['class_id' => ['required', 'integer']]);
        $classId = $request->integer('class_id');
        $slots = $this->timetable->slots(fn ($q) => $q->where('class_id', $classId));
        $all = $this->timetable->slots();
        $ids = $slots->pluck('id')->all();

        return ApiResponse::success([
            'slots' => $slots,
            'conflicts' => array_values(array_filter($this->timetable->conflicts($all), fn ($c) => array_intersect(array_column($c['slots'], 'id'), $ids) !== [])),
        ]);
    }

    /** A teacher's week: their times, and the substitutions of that week (covering, or away). */
    public function forTeacher(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);
        $request->validate(['staff_id' => ['required', 'integer'], 'week' => ['nullable', 'date']]);
        $staffId = $request->integer('staff_id');
        $monday = Carbon::parse((string) ($request->input('week') ?: now()->toDateString()))->startOfWeek(Carbon::MONDAY);

        $slots = $this->timetable->slots()->filter(fn ($s) => in_array($staffId, array_column($s['teachers'], 'id'), true))->values();
        $subs = TeacherSubstitution::query()->with(['schoolClass:id,name', 'absentStaff', 'substituteStaff'])
            ->whereDate('date', '>=', $monday->toDateString())->whereDate('date', '<=', $monday->copy()->addDays(6)->toDateString())
            ->where(fn ($q) => $q->where('substitute_staff_id', $staffId)->orWhere('absent_staff_id', $staffId))
            ->orderBy('date')->get()
            ->map(fn (TeacherSubstitution $s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'day_of_week' => $s->date->dayOfWeekIso,
                'start_time' => $s->start_time ? substr((string) $s->start_time, 0, 5) : null,
                'end_time' => $s->end_time ? substr((string) $s->end_time, 0, 5) : null,
                'class' => $s->schoolClass?->name,
                'role' => $s->substitute_staff_id === $staffId ? 'covering' : 'away',
                'other' => $s->substitute_staff_id === $staffId ? $s->absentStaff?->fullName() : $s->substituteStaff?->fullName(),
            ])->values();
        $minutes = $slots->sum(fn ($s) => (strtotime($s['end_time']) - strtotime($s['start_time'])) / 60);

        return ApiResponse::success([
            'week_start' => $monday->toDateString(),
            'slots' => $slots,
            'substitutions' => $subs,
            'hours_per_week' => round($minutes / 60, 2),
        ]);
    }

    public function forRoom(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);
        $request->validate(['classroom_id' => ['required', 'integer']]);
        $roomId = $request->integer('classroom_id');

        return ApiResponse::success(['slots' => $this->timetable->slots()->where('room_id', $roomId)->values()]);
    }

    /**
     * Hours per subject of a class: in the timetable, wanted by the
     * curriculum of its grade level, and set on teacher assignments.
     */
    public function subjects(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);
        $request->validate(['class_id' => ['required', 'integer', Rule::exists('tenant.classes', 'id')]]);
        $class = SchoolClass::query()->findOrFail($request->integer('class_id'));
        $db = DB::connection('tenant');

        $timetable = $this->timetable->slots(fn ($q) => $q->where('class_id', $class->id))
            ->groupBy(fn ($s) => (int) ($s['book_id'] ?? 0))
            ->map(fn ($rows) => round($rows->sum(fn ($s) => (strtotime($s['end_time']) - strtotime($s['start_time'])) / 3600), 2));
        $curriculum = $class->grade_level_id === null ? collect() : $db->table('book_grade_level')->where('grade_level_id', $class->grade_level_id)
            ->pluck('hours_per_week', 'book_id')->map(fn ($h) => $h !== null ? (float) $h : null);
        $assigned = $db->table('teacher_assignments')->where('class_id', $class->id)->whereNotNull('book_id')
            ->selectRaw('book_id, sum(hours_per_week) as hours')->groupBy('book_id')->pluck('hours', 'book_id')->map(fn ($h) => $h !== null ? (float) $h : null);

        $ids = collect($timetable->keys())->merge($curriculum->keys())->merge($assigned->keys())->map(fn ($id) => (int) $id)->unique()->values();
        $titles = Book::query()->whereIn('id', $ids->filter())->pluck('title', 'id');

        $rows = $ids->map(fn (int $id) => [
            'book_id' => $id ?: null,
            'subject' => $id ? ($titles[$id] ?? null) : null,
            'timetable_hours' => (float) ($timetable[$id] ?? 0),
            'curriculum_hours' => $curriculum[$id] ?? null,
            'assigned_hours' => $assigned[$id] ?? null,
        ])->sortBy(fn ($r) => $r['subject'] ?? '~')->values();

        return ApiResponse::success(['class' => $class->name, 'has_grade' => $class->grade_level_id !== null, 'subjects' => $rows]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_VIEW);

        return ApiResponse::success($this->timetable->conflicts());
    }

    // --- Editing a class's weekly times -----------------------------------------------

    public function storeSlot(Request $request): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_MANAGE);
        $data = $this->validatedSlot($request);
        $slot = ClassSchedule::query()->create($data);

        return ApiResponse::created($this->afterSave($slot));
    }

    public function updateSlot(Request $request, ClassSchedule $schedule): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_MANAGE);
        $schedule->update($this->validatedSlot($request, $schedule));

        return ApiResponse::success($this->afterSave($schedule));
    }

    public function destroySlot(Request $request, ClassSchedule $schedule): JsonResponse
    {
        $this->can($request, Permissions::TIMETABLE_MANAGE);
        $schedule->delete();

        return ApiResponse::noContent();
    }

    // --- Helpers -------------------------------------------------------------------------

    /** The slot as saved, with any conflict it is now part of (a warning, not a refusal). @return array<string, mixed> */
    private function afterSave(ClassSchedule $slot): array
    {
        $all = $this->timetable->slots();

        return [
            'slot' => $all->firstWhere('id', $slot->id),
            'conflicts' => array_values(array_filter($this->timetable->conflicts($all), fn ($c) => in_array($slot->id, array_column($c['slots'], 'id'), true))),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedSlot(Request $request, ?ClassSchedule $slot = null): array
    {
        $data = $request->validate([
            'class_id' => [$slot ? 'sometimes' : 'required', 'integer', Rule::exists('tenant.classes', 'id')->whereNull('deleted_at')],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'book_id' => ['nullable', 'integer', Rule::exists('tenant.books', 'id')->whereNull('deleted_at')],
            'staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('tenant.classrooms', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $classId = $data['class_id'] ?? $slot?->class_id;

        $taken = ClassSchedule::query()->where('class_id', $classId)->where('day_of_week', $data['day_of_week'])
            ->where('start_time', $data['start_time'])
            ->when($slot !== null, fn ($q) => $q->whereKeyNot($slot->getKey()))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['start_time' => __('This class already has a time starting then on that day.')]);
        }

        return $data;
    }

    private function myStaffId(Request $request): ?int
    {
        $id = Staff::query()->where('user_id', $request->user()->getKey())->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }
}
