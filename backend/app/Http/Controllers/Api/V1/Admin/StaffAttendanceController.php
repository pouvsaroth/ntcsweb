<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffAttendanceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Query\ApiQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Attendance & Time: Employee attendance (the month sheet) and
 * Check-in / check-out (every day's record, HR's own entries, imports).
 */
final class StaffAttendanceController extends Controller
{
    public function __construct(private readonly StaffAttendanceService $attendance) {}

    /** Stored days — the Check-in / check-out log. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffAttendance::class);

        $query = StaffAttendance::query()->with(['staff', 'shift']);

        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->date('to'));
        }
        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->whereHas('staff', fn (Builder $s) => $s->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])->orWhereRaw('LOWER(employee_code) LIKE ?', [$term]));
        }

        $rows = ApiQuery::for($query, $request)
            ->filterable(['staff_id', 'status', 'check_in_source'])
            ->sortable(['date', 'check_in_at', 'late_minutes', 'early_leave_minutes', 'overtime_minutes'], default: '-date')
            ->paginate();

        return ApiResponse::success(StaffAttendanceResource::collection($rows));
    }

    /** HR sets one day's check-in/out for someone (creating the day if needed). */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', StaffAttendance::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = $this->attendance->record(
            Staff::query()->findOrFail($data['staff_id']),
            $data['date'],
            $data['check_in'] ?? '',
            $data['check_out'] ?? '',
            StaffAttendance::SOURCE_MANUAL,
            $data['note'] ?? '',
            $request->user(),
        );

        return ApiResponse::success(new StaffAttendanceResource($row->load(['staff', 'shift'])), status: $row->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, StaffAttendance $staffAttendance): JsonResponse
    {
        $this->authorize('update', $staffAttendance);

        $data = $request->validate([
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = $this->attendance->record(
            $staffAttendance->staff,
            $staffAttendance->date->toDateString(),
            array_key_exists('check_in', $data) ? ($data['check_in'] ?? '') : null,
            array_key_exists('check_out', $data) ? ($data['check_out'] ?? '') : null,
            StaffAttendance::SOURCE_MANUAL,
            array_key_exists('note', $data) ? ($data['note'] ?? '') : null,
            $request->user(),
        );

        return ApiResponse::success(new StaffAttendanceResource($row->load(['staff', 'shift'])));
    }

    public function destroy(StaffAttendance $staffAttendance): JsonResponse
    {
        $this->authorize('delete', $staffAttendance);

        $this->attendance->delete($staffAttendance);

        return ApiResponse::noContent();
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', StaffAttendance::class);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        return ApiResponse::success($this->attendance->import((string) file_get_contents($request->file('file')->getRealPath()), $request->user()));
    }

    /**
     * Late, Early leave, Absence and Overtime: each occurrence in a date
     * range (up to three months), and a total per person. Late / early /
     * overtime come from the recorded days; absences are worked out the
     * same way as the month sheet.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffAttendance::class);

        $data = $request->validate([
            'type' => ['required', Rule::in(['late', 'early_leave', 'absence', 'overtime'])],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $from = CarbonImmutable::parse($data['from'], $this->attendance->timezone());
        $to = CarbonImmutable::parse($data['to'], $this->attendance->timezone());
        abort_if($from->diffInDays($to) > 92, 422, 'Choose at most three months.');

        $staff = $this->staffQuery($data)->get();
        $items = [];

        if ($data['type'] === 'absence') {
            foreach ($this->attendance->sheet($staff, $from, $to) as $row) {
                foreach ($row['days'] as $day) {
                    if ($day['status'] === StaffAttendance::STATUS_ABSENT) {
                        $items[] = ['staff' => $row['staff'], 'date' => $day['date'], 'minutes' => 0, 'shift' => $day['shift'], 'check_in_at' => null, 'check_out_at' => null];
                    }
                }
            }
        } else {
            $column = ['late' => 'late_minutes', 'early_leave' => 'early_leave_minutes', 'overtime' => 'overtime_minutes'][$data['type']];
            $rows = StaffAttendance::query()->with('shift')->whereIn('staff_id', $staff->modelKeys())
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->where($column, '>', 0)->orderByDesc('date')->get();
            $byId = $staff->keyBy('id');

            foreach ($rows as $row) {
                $items[] = [
                    'staff' => $byId->get($row->staff_id),
                    'date' => $row->date->toDateString(),
                    'minutes' => $row->{$column},
                    'shift' => $row->shift !== null ? ['id' => $row->shift->id, 'name' => $row->shift->name, 'color' => $row->shift->color] : null,
                    'check_in_at' => $row->check_in_at?->toIso8601String(),
                    'check_out_at' => $row->check_out_at?->toIso8601String(),
                    'record_id' => $row->id,
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        $summary = collect($items)->groupBy(fn (array $item) => $item['staff']->id)->map(fn ($group) => [
            'staff_id' => $group->first()['staff']->id,
            'name' => $group->first()['staff']->fullName(),
            'employee_code' => $group->first()['staff']->employee_code,
            'count' => $group->count(),
            'minutes' => $group->sum('minutes'),
        ])->sortByDesc('count')->values();

        return ApiResponse::success([
            'items' => array_map(fn (array $item) => [...$item, 'staff' => [
                'id' => $item['staff']->id, 'name' => $item['staff']->fullName(), 'employee_code' => $item['staff']->employee_code,
            ]], array_slice($items, 0, 1000)),
            'summary' => $summary,
            'total' => count($items),
        ]);
    }

    /**
     * Working staff matching the filters.
     *
     * @param  array<string, mixed>  $data
     * @return Builder<Staff>
     */
    public function staffQuery(array $data): Builder
    {
        return Staff::query()
            ->whereIn('status', Staff::STATUSES_WORKING)
            ->when($data['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($data['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($data['staff_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($data['search'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%'.mb_strtolower($term).'%'])
                ->orWhereRaw('LOWER(employee_code) LIKE ?', ['%'.mb_strtolower($term).'%'])))
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(300);
    }

    /** Employee attendance: a month, everyone (or one department/branch), day by day. */
    public function sheet(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffAttendance::class);

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $month = $data['month'] ?? $this->attendance->localNow()->format('Y-m');
        $from = CarbonImmutable::parse("{$month}-01", $this->attendance->timezone());

        $staff = $this->staffQuery($data)->get();

        $sheet = $this->attendance->sheet($staff, $from, $from->endOfMonth()->startOfDay());

        return ApiResponse::success([
            'month' => $month,
            'staff' => array_map(fn (array $row) => [
                'id' => $row['staff']->id,
                'name' => $row['staff']->fullName(),
                'employee_code' => $row['staff']->employee_code,
                'days' => $row['days'],
                'totals' => $row['totals'],
                'locked' => $row['locked'],
            ], $sheet),
        ]);
    }
}
