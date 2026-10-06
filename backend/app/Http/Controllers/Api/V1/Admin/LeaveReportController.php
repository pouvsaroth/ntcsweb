<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalFlowStep;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Support\Approvals\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Leave Management's read-only views over staff leave requests:
 * Leave calendar (who is off in a month), Leave reports (days taken by type,
 * month and staff in a year) and Approval workflow (the staff-leave approval
 * flow set in Approval Flow > Flow Setting, and how many requests wait).
 */
final class LeaveReportController extends Controller
{
    /** Staff leave overlapping a month (approved, and pending when asked), with that month's holidays. */
    public function calendar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'leave_type_id' => ['nullable', 'integer'],
            'include_pending' => ['sometimes', 'boolean'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        $to = $from->endOfMonth()->startOfDay();

        $statuses = $request->boolean('include_pending')
            ? [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING]
            : [LeaveRequest::STATUS_APPROVED];

        $leaves = LeaveRequest::query()
            ->with(['staff', 'leaveType'])
            ->whereNotNull('staff_id')
            ->whereIn('status', $statuses)
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->when($data['leave_type_id'] ?? null, fn ($q, $typeId) => $q->where('leave_type_id', $typeId))
            ->orderBy('from_date')
            ->get();

        $holidays = Holiday::query()
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<=', $to->toDateString())
            ->orderBy('start_date')
            ->get();

        return ApiResponse::success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'holidays' => $holidays->map(fn (Holiday $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'start_date' => $h->start_date->toDateString(),
                'end_date' => $h->end_date->toDateString(),
            ])->values(),
            'leaves' => $leaves->map(fn (LeaveRequest $leave) => [
                'id' => $leave->id,
                'staff' => $leave->staff ? $this->staffRow($leave->staff) : null,
                'leave_type' => $leave->leaveType ? $this->typeRow($leave->leaveType) : null,
                'from_date' => $leave->from_date->toDateString(),
                'to_date' => $leave->to_date->toDateString(),
                'day_part' => $leave->day_part,
                'days' => $leave->days,
                'status' => $leave->status,
            ])->values(),
        ]);
    }

    /**
     * A year's approved staff leave (those with a leave type — older untyped
     * requests have no day count): days by type, by month and type, and by
     * staff member and type, most days first.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        $year = max(2000, min(2100, $request->integer('year', (int) now()->year)));
        $typeId = $request->filled('leave_type_id') ? $request->integer('leave_type_id') : null;

        $requests = LeaveRequest::query()
            ->with(['staff', 'leaveType'])
            ->whereNotNull('staff_id')
            ->whereNotNull('leave_type_id')
            ->whereYear('from_date', $year)
            ->when($typeId, fn ($q) => $q->where('leave_type_id', $typeId))
            ->get();

        $approved = $requests->where('status', LeaveRequest::STATUS_APPROVED);
        $types = $approved->pluck('leaveType')->filter()->unique('id')->sortBy('name')->values();

        $byType = $types->map(fn (LeaveType $type) => [
            'leave_type' => $this->typeRow($type),
            'days' => (float) $approved->where('leave_type_id', $type->id)->sum('days'),
            'requests' => $approved->where('leave_type_id', $type->id)->count(),
            'staff' => $approved->where('leave_type_id', $type->id)->unique('staff_id')->count(),
        ])->values();

        $byMonth = collect(range(1, 12))->map(fn (int $month) => [
            'month' => $month,
            'days' => (float) $approved->filter(fn (LeaveRequest $r) => $r->from_date->month === $month)->sum('days'),
            'by_type' => $types->mapWithKeys(fn (LeaveType $type) => [
                $type->id => (float) $approved->filter(fn (LeaveRequest $r) => $r->from_date->month === $month && $r->leave_type_id === $type->id)->sum('days'),
            ]),
        ])->values();

        $byStaff = $approved->groupBy('staff_id')->map(fn ($rows) => [
            'staff' => $this->staffRow($rows->first()->staff),
            'days' => (float) $rows->sum('days'),
            'requests' => $rows->count(),
            'by_type' => $rows->groupBy('leave_type_id')->map(fn ($r) => (float) $r->sum('days')),
        ])->sortByDesc('days')->values();

        return ApiResponse::success([
            'year' => $year,
            'totals' => [
                'days' => (float) $approved->sum('days'),
                'requests' => $approved->count(),
                'staff' => $approved->unique('staff_id')->count(),
                'pending_requests' => $requests->where('status', LeaveRequest::STATUS_PENDING)->count(),
                'pending_days' => (float) $requests->where('status', LeaveRequest::STATUS_PENDING)->sum('days'),
                'rejected_requests' => $requests->where('status', LeaveRequest::STATUS_REJECTED)->count(),
            ],
            'by_type' => $byType,
            'by_month' => $byMonth,
            'by_staff' => $byStaff,
        ]);
    }

    /**
     * The staff-leave approval flow (Approval Flow > Flow Setting): its steps
     * with each group's members — or none, when whoever holds the leave
     * approve permission decides — and this year's requests by status.
     */
    public function workflow(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        $steps = ApprovalFlowStep::query()
            ->with('group.members')
            ->where('document_type', DocumentType::STAFF_LEAVE)
            ->orderBy('step_order')
            ->get();
        $names = User::query()
            ->whereIn('id', $steps->flatMap(fn (ApprovalFlowStep $step) => $step->group?->members->pluck('user_id') ?? []))
            ->pluck('name', 'id');

        $year = (int) now()->year;
        $counts = LeaveRequest::query()
            ->whereNotNull('staff_id')
            ->whereYear('from_date', $year)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $oldestPending = LeaveRequest::query()->whereNotNull('staff_id')->where('status', LeaveRequest::STATUS_PENDING)->min('created_at');

        return ApiResponse::success([
            'steps' => $steps->map(fn (ApprovalFlowStep $step) => [
                'step_order' => $step->step_order,
                'group' => [
                    'id' => $step->approval_group_id,
                    'name' => $step->group?->name,
                    'members' => $step->group?->members->map(fn ($member) => $names[$member->user_id] ?? null)->filter()->values() ?? [],
                ],
            ])->values(),
            'year' => $year,
            'counts' => [
                'pending' => (int) ($counts[LeaveRequest::STATUS_PENDING] ?? 0),
                'approved' => (int) ($counts[LeaveRequest::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($counts[LeaveRequest::STATUS_REJECTED] ?? 0),
            ],
            'oldest_pending_at' => $oldestPending ? CarbonImmutable::parse($oldestPending)->toIso8601String() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function staffRow(Staff $staff): array
    {
        return ['id' => $staff->id, 'name' => $staff->fullName(), 'employee_code' => $staff->employee_code];
    }

    /** @return array<string, mixed> */
    private function typeRow(LeaveType $type): array
    {
        return ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'color' => $type->color];
    }
}
