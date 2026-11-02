<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StaffSalaryRequest;
use App\Http\Resources\StaffSalaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HRM > Payroll > Basic salary — every working staff member with the salary
 * in effect today (null when none is set yet), and each one's history. A
 * raise is a new row from its own date; see StaffSalary. A salary can be
 * "pay by shift" — a monthly amount per shift (StaffSalaryShift).
 */
final class StaffSalaryController extends Controller
{
    private const WITH = ['structure', 'shifts.shift'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffSalary::class);

        $today = now()->toDateString();

        $staff = ApiQuery::for(
            Staff::query()
                ->whereIn('status', Staff::STATUSES_WORKING)
                ->with(['position', 'department'])
                // "Not set yet" first is the useful view while setting up.
                ->when($request->boolean('missing_only'), fn ($query) => $query->whereDoesntHave('salaries', fn ($q) => $q->whereDate('effective_from', '<=', $today))),
            $request,
        )
            ->searchable('first_name', 'last_name', 'employee_code')
            ->sortable(['employee_code', 'first_name'], default: 'employee_code')
            ->maxPerPage(100)
            ->paginate();

        $current = StaffSalary::query()
            ->whereIn('staff_id', $staff->getCollection()->modelKeys())
            ->effectiveOn($today)
            ->with(self::WITH)
            ->get()
            ->keyBy('staff_id');

        $upcoming = StaffSalary::query()
            ->whereIn('staff_id', $staff->getCollection()->modelKeys())
            ->whereDate('effective_from', '>', $today)
            ->orderBy('effective_from')
            ->with(self::WITH)
            ->get()
            ->groupBy('staff_id')
            ->map->first();

        $rows = $staff->through(fn (Staff $member) => [
            'staff' => $this->staffRow($member),
            'salary' => isset($current[$member->id]) ? new StaffSalaryResource($current[$member->id]) : null,
            // A raise already entered for later — shown so it isn't entered twice.
            'next_salary' => isset($upcoming[$member->id]) ? new StaffSalaryResource($upcoming[$member->id]) : null,
        ]);

        return ApiResponse::success($rows);
    }

    public function history(Staff $staff): JsonResponse
    {
        $this->authorize('viewAny', StaffSalary::class);

        $salaries = StaffSalary::query()
            ->where('staff_id', $staff->id)
            ->with(self::WITH)
            ->orderByDesc('effective_from')
            ->get();

        return ApiResponse::success([
            'staff' => $this->staffRow($staff->load(['position', 'department'])),
            'salaries' => StaffSalaryResource::collection($salaries),
        ]);
    }

    public function store(StaffSalaryRequest $request): JsonResponse
    {
        $this->authorize('create', StaffSalary::class);

        $salary = DB::connection('tenant')->transaction(function () use ($request) {
            $salary = StaffSalary::query()->create([...$request->salaryData(), 'created_by' => $request->user()->getKey()]);
            $this->syncShifts($salary, $request->validated('shifts', []));

            return $salary;
        });

        return ApiResponse::created(new StaffSalaryResource($salary->load(self::WITH)));
    }

    public function update(StaffSalaryRequest $request, StaffSalary $staffSalary): JsonResponse
    {
        $this->authorize('update', $staffSalary);

        DB::connection('tenant')->transaction(function () use ($request, $staffSalary) {
            $staffSalary->update($request->salaryData());
            if ($request->has('shifts')) {
                $this->syncShifts($staffSalary, $request->validated('shifts', []));
            }
        });

        return ApiResponse::success(new StaffSalaryResource($staffSalary->load(self::WITH)));
    }

    public function destroy(StaffSalary $staffSalary): JsonResponse
    {
        $this->authorize('delete', $staffSalary);

        $staffSalary->delete();

        return ApiResponse::noContent();
    }

    /** @param  list<array{shift_id:int, amount:numeric-string|float}>  $lines */
    private function syncShifts(StaffSalary $salary, array $lines): void
    {
        $keep = collect($lines)->pluck('shift_id')->map(fn ($id) => (int) $id)->all();
        $salary->shifts()->whereNotIn('shift_id', $keep)->delete();

        foreach ($lines as $line) {
            $salary->shifts()->updateOrCreate(['shift_id' => (int) $line['shift_id']], ['amount' => $line['amount']]);
        }
    }

    /** @return array<string, mixed> */
    private function staffRow(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->fullName(),
            'employee_code' => $staff->employee_code,
            'position' => $staff->position?->name,
            'department' => $staff->department?->name,
            'hire_date' => $staff->hire_date?->toDateString(),
        ];
    }
}
