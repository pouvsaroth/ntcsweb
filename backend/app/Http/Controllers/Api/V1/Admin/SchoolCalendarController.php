<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicTerm;
use App\Models\Holiday;
use App\Models\Meeting;
use App\Models\SchoolCalendarEvent;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Academics > School setup > School calendar (docs/academics/school-setup.md):
 * one view of the school's days — its own calendar days (opening, closing,
 * exams, events), the Holidays list, the terms and — for people who can
 * see Front Office meetings — the meetings arranged there (read live, so a
 * moved or cancelled meeting is never stale here).
 */
final class SchoolCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::SCHOOL_CALENDAR_VIEW), 403);
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse((string) $request->input('from'))->toDateString();
        $to = Carbon::parse((string) $request->input('to'))->toDateString();
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            $to = Carbon::parse($from)->addDays(400)->toDateString();
        }

        $overlaps = fn ($q) => $q->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);

        $events = SchoolCalendarEvent::query()->with('academicYear:id,name')->where($overlaps)
            ->orderBy('start_date')->orderBy('id')->get()
            ->map(fn (SchoolCalendarEvent $e) => $this->row($e))->values();

        $holidays = Holiday::query()->where($overlaps)->orderBy('start_date')->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id,
                'title' => $h->name,
                'holiday_type' => $h->holiday_type,
                'start_date' => $h->start_date->toDateString(),
                'end_date' => $h->end_date->toDateString(),
                'description' => $h->description,
            ])->values();

        $terms = AcademicTerm::query()->with('academicYear:id,name')
            ->whereNotNull('start_date')->whereNotNull('end_date')->where($overlaps)
            ->orderBy('start_date')->get()
            ->map(fn (AcademicTerm $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'academic_year' => $t->academicYear?->name,
                'start_date' => $t->start_date->toDateString(),
                'end_date' => $t->end_date->toDateString(),
            ])->values();

        $meetings = $request->user()->hasPermission(Permissions::MEETINGS_VIEW)
            ? Meeting::query()->whereBetween('meeting_date', [$from, $to])->where('status', '!=', 'cancelled')
                ->orderBy('meeting_date')->orderBy('start_time')->get()
                ->map(fn (Meeting $m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'date' => $m->meeting_date->toDateString(),
                    'start_time' => substr((string) $m->start_time, 0, 5),
                    'end_time' => $m->end_time ? substr((string) $m->end_time, 0, 5) : null,
                    'location' => $m->location,
                    'status' => $m->status,
                ])->values()
            : [];

        return ApiResponse::success(['events' => $events, 'holidays' => $holidays, 'terms' => $terms, 'meetings' => $meetings]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::SCHOOL_CALENDAR_MANAGE), 403);

        $event = SchoolCalendarEvent::query()->create($this->validated($request));

        return ApiResponse::created($this->row($event->load('academicYear:id,name')));
    }

    public function update(Request $request, SchoolCalendarEvent $schoolCalendarEvent): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::SCHOOL_CALENDAR_MANAGE), 403);

        $schoolCalendarEvent->update($this->validated($request));

        return ApiResponse::success($this->row($schoolCalendarEvent->load('academicYear:id,name')));
    }

    public function destroy(Request $request, SchoolCalendarEvent $schoolCalendarEvent): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::SCHOOL_CALENDAR_MANAGE), 403);

        $schoolCalendarEvent->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(SchoolCalendarEvent::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('tenant.academic_years', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        // One day: the same start and end date.
        $data['end_date'] = $data['end_date'] ?? $data['start_date'];

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(SchoolCalendarEvent $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'type' => $e->type,
            'start_date' => $e->start_date->toDateString(),
            'end_date' => $e->end_date->toDateString(),
            'academic_year_id' => $e->academic_year_id,
            'academic_year' => $e->academicYear?->name,
            'description' => $e->description,
        ];
    }
}
