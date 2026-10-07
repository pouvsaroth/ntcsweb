<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PerformanceGoal;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HRM > Performance Management > Goals — every staff member's goals, by cycle. */
final class PerformanceGoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PerformanceGoal::class);

        $goals = ApiQuery::for(PerformanceGoal::query()->with(['staff', 'cycle']), $request)
            ->searchable('title')
            ->filterable(['staff_id', 'performance_cycle_id', 'status'])
            ->sortable(['due_date', 'created_at'], default: '-created_at')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($goals->through(fn (PerformanceGoal $goal) => $this->row($goal)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PerformanceGoal::class);

        $goal = PerformanceGoal::query()->create([...$this->validated($request, null), 'created_by' => $request->user()->getKey()]);

        return ApiResponse::created($this->row($goal->load(['staff', 'cycle'])));
    }

    public function update(Request $request, PerformanceGoal $performanceGoal): JsonResponse
    {
        $this->authorize('update', $performanceGoal);

        $data = $this->validated($request, $performanceGoal);
        // Done means done.
        if (($data['status'] ?? null) === PerformanceGoal::STATUS_COMPLETED) {
            $data['progress'] = 100;
        }
        $performanceGoal->update($data);

        return ApiResponse::success($this->row($performanceGoal->load(['staff', 'cycle'])));
    }

    public function destroy(PerformanceGoal $performanceGoal): JsonResponse
    {
        $this->authorize('delete', $performanceGoal);

        $performanceGoal->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PerformanceGoal $goal): array
    {
        $required = $goal === null ? 'required' : 'sometimes';

        return $request->validate([
            'staff_id' => $goal === null ? ['required', 'integer', Rule::exists('tenant.staff', 'id')] : ['prohibited'],
            'performance_cycle_id' => ['nullable', 'integer', Rule::exists('tenant.performance_cycles', 'id')],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],
            'weight' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(PerformanceGoal::STATUSES)],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(PerformanceGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'staff' => $goal->staff !== null ? ['id' => $goal->staff->id, 'name' => $goal->staff->fullName(), 'employee_code' => $goal->staff->employee_code] : null,
            'cycle' => $goal->cycle !== null ? ['id' => $goal->cycle->id, 'name' => $goal->cycle->name] : null,
            'title' => $goal->title,
            'description' => $goal->description,
            'due_date' => $goal->due_date?->toDateString(),
            'weight' => $goal->weight,
            'progress' => $goal->progress,
            'status' => $goal->status,
            'self_rating' => $goal->self_rating,
            'manager_rating' => $goal->manager_rating,
        ];
    }
}
