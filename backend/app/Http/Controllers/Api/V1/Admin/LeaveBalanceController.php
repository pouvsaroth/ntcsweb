<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Leave Management > Leave balance and Carry forward: each working
 * staff member's balance of every leave type a policy covers, HR's manual
 * adjustments, and the year-end carry forward. The arithmetic is
 * LeaveBalanceService's.
 */
final class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
    ) {}

    /** One row per working staff member, with this year's balance of each active leave type a policy covers. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveBalanceEntry::class);

        $year = $this->year($request);
        $types = LeaveType::query()->where('is_active', true)
            ->when($request->filled('leave_type_id'), fn ($q) => $q->whereKey($request->integer('leave_type_id')))
            ->orderBy('name')->get();

        $staff = ApiQuery::for(Staff::query()->whereIn('status', Staff::STATUSES_WORKING), $request)
            ->searchable('first_name', 'last_name', 'employee_code')
            ->sortable(['employee_code', 'first_name'], default: 'employee_code')
            ->maxPerPage(100)
            ->paginate();

        $rows = $staff->through(fn (Staff $member) => [
            'staff' => $this->staffRow($member),
            'balances' => $types
                ->map(fn (LeaveType $type) => ($balance = $this->balances->balance($member, $type, $year)) === null
                    ? null
                    : ['leave_type' => $this->typeRow($type), ...$balance])
                ->filter()
                ->values(),
        ]);

        return ApiResponse::success($rows);
    }

    /** One staff member's year: each leave type's balance, and the entries behind it. */
    public function show(Request $request, Staff $staff): JsonResponse
    {
        $this->authorize('viewAny', LeaveBalanceEntry::class);

        $year = $this->year($request);
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();

        return ApiResponse::success([
            'staff' => $this->staffRow($staff),
            'year' => $year,
            'balances' => $types
                ->map(fn (LeaveType $type) => ($balance = $this->balances->balance($staff, $type, $year)) === null
                    ? null
                    : ['leave_type' => $this->typeRow($type), ...$balance])
                ->filter()
                ->values(),
            'entries' => LeaveBalanceEntry::query()
                ->with(['leaveType', 'createdBy'])
                ->where('staff_id', $staff->id)
                ->where('year', $year)
                ->latest()
                ->get()
                ->map(fn (LeaveBalanceEntry $entry) => $this->entryRow($entry)),
        ]);
    }

    /** HR adds (or with negative days, takes away) days of a leave type for a year, with a note. */
    public function adjust(Request $request): JsonResponse
    {
        $this->authorize('create', LeaveBalanceEntry::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', 'exists:tenant.leave_types,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'days' => ['required', 'numeric', 'between:-366,366', 'multiple_of:0.5', 'not_in:0'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $entry = LeaveBalanceEntry::query()->create([
            ...$data,
            'kind' => LeaveBalanceEntry::KIND_ADJUSTMENT,
            'created_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created($this->entryRow($entry->load(['leaveType', 'createdBy'])));
    }

    /** Removes a manual adjustment — carry forward is replaced by running it again instead. */
    public function destroyEntry(LeaveBalanceEntry $leaveBalanceEntry): JsonResponse
    {
        $this->authorize('delete', $leaveBalanceEntry);

        if ($leaveBalanceEntry->kind !== LeaveBalanceEntry::KIND_ADJUSTMENT) {
            return ApiResponse::error('Carried-forward days are replaced by running carry forward again, not deleted one by one.', 422);
        }

        $leaveBalanceEntry->delete();

        return ApiResponse::noContent();
    }

    /** What carry forward moved into a year. */
    public function carried(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveBalanceEntry::class);

        $query = LeaveBalanceEntry::query()
            ->with(['staff', 'leaveType', 'createdBy'])
            ->where('kind', LeaveBalanceEntry::KIND_CARRY_FORWARD)
            ->where('year', $this->year($request));

        $entries = ApiQuery::for($query, $request)
            ->filterable(['leave_type_id', 'staff_id'])
            ->sortable(['days', 'staff_id'], default: 'staff_id')
            ->maxPerPage(200)
            ->paginate()
            ->through(fn (LeaveBalanceEntry $entry) => [...$this->entryRow($entry), 'staff' => $entry->staff ? $this->staffRow($entry->staff) : null]);

        return ApiResponse::success($entries);
    }

    /** Runs year-end carry forward from `from_year` into the year after (again replaces the earlier run). */
    public function carryForward(Request $request): JsonResponse
    {
        $this->authorize('create', LeaveBalanceEntry::class);

        $data = $request->validate(['from_year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return ApiResponse::success($this->balances->carryForward((int) $data['from_year'], $request->user()));
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return max(2000, min(2100, $year));
    }

    /** @return array<string, mixed> */
    private function staffRow(Staff $staff): array
    {
        return ['id' => $staff->id, 'name' => $staff->fullName(), 'employee_code' => $staff->employee_code, 'hire_date' => $staff->hire_date?->toDateString()];
    }

    /** @return array<string, mixed> */
    private function typeRow(LeaveType $type): array
    {
        return ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'color' => $type->color];
    }

    /** @return array<string, mixed> */
    private function entryRow(LeaveBalanceEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'leave_type' => $entry->leaveType ? $this->typeRow($entry->leaveType) : null,
            'year' => $entry->year,
            'kind' => $entry->kind,
            'days' => $entry->days,
            'expires_on' => $entry->expires_on?->toDateString(),
            'note' => $entry->note,
            'created_by' => $entry->createdBy?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
