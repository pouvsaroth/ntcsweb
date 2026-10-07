<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StaffPayComponentRequest;
use App\Http\Resources\StaffPayComponentResource;
use App\Http\Responses\ApiResponse;
use App\Models\StaffPayComponent;
use App\Models\StaffSalary;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Payroll > Allowances / Bonuses / Deductions — what each staff member
 * is given. `kind` narrows to one tab's components; `current=1` keeps only
 * what's still in effect today or later (recurring not yet ended, or a
 * one-time item not yet paid).
 */
final class StaffPayComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffPayComponent::class);

        $today = now()->toDateString();

        $query = $this->query()
            ->when($request->filled('kind'), fn ($q) => $q->whereHas('component', fn ($c) => $c->where('kind', $request->string('kind'))))
            ->when($request->boolean('current'), fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($r) => $r->where('recurrence', StaffPayComponent::RECURRING)->where(fn ($e) => $e->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today)))
                ->orWhere(fn ($o) => $o->where('recurrence', StaffPayComponent::ONCE)->whereDate('starts_on', '>=', now()->startOfMonth()->toDateString()))));

        $assignments = ApiQuery::for($query, $request)
            ->filterable(['staff_id', 'payroll_component_id', 'recurrence'])
            ->sortable(['starts_on'], default: '-starts_on')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(StaffPayComponentResource::collection($assignments));
    }

    public function store(StaffPayComponentRequest $request): JsonResponse
    {
        $this->authorize('create', StaffPayComponent::class);

        $assignment = StaffPayComponent::query()->create([...$request->validated(), 'created_by' => $request->user()->getKey()]);

        return ApiResponse::created(new StaffPayComponentResource($this->query()->findOrFail($assignment->id)));
    }

    public function update(StaffPayComponentRequest $request, StaffPayComponent $staffPayComponent): JsonResponse
    {
        $this->authorize('update', $staffPayComponent);

        $data = $request->validated();
        // Switching to one-time drops an end date it no longer has.
        if (($data['recurrence'] ?? null) === StaffPayComponent::ONCE) {
            $data['ends_on'] = null;
        }

        $staffPayComponent->update($data);

        return ApiResponse::success(new StaffPayComponentResource($this->query()->findOrFail($staffPayComponent->id)));
    }

    public function destroy(StaffPayComponent $staffPayComponent): JsonResponse
    {
        $this->authorize('delete', $staffPayComponent);

        $staffPayComponent->delete();

        return ApiResponse::noContent();
    }

    /** With the staff member's current salary currency — what a fixed amount is in. */
    private function query(): Builder
    {
        return StaffPayComponent::query()
            ->select('staff_pay_components.*')
            ->addSelect(['salary_currency' => StaffSalary::query()
                ->select('currency')
                ->whereColumn('staff_salaries.staff_id', 'staff_pay_components.staff_id')
                ->whereDate('effective_from', '<=', now()->toDateString())
                ->orderByDesc('effective_from')
                ->limit(1)])
            ->with(['staff', 'component']);
    }
}
