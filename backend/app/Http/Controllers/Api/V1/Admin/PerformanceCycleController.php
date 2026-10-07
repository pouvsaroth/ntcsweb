<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PerformanceCycle;
use App\Models\PerformanceSetting;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Performance Management > Performance review — review cycles, and the
 * score weights (KPIs + goals + manager assessment = 100).
 */
final class PerformanceCycleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PerformanceCycle::class);

        $cycles = ApiQuery::for(PerformanceCycle::query()->with('form')->withCount('goals'), $request)
            ->searchable('name')
            ->filterable(['status'])
            ->sortable(['start_date', 'name'], default: '-start_date')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($cycles->through(fn (PerformanceCycle $cycle) => $this->row($cycle)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PerformanceCycle::class);

        $cycle = PerformanceCycle::query()->create($this->validated($request, null));

        return ApiResponse::created($this->row($cycle->load('form')->loadCount('goals')));
    }

    public function update(Request $request, PerformanceCycle $performanceCycle): JsonResponse
    {
        $this->authorize('update', $performanceCycle);

        $performanceCycle->update($this->validated($request, $performanceCycle));

        return ApiResponse::success($this->row($performanceCycle->load('form')->loadCount('goals')));
    }

    public function destroy(PerformanceCycle $performanceCycle): JsonResponse
    {
        $this->authorize('delete', $performanceCycle);

        if ($performanceCycle->status !== PerformanceCycle::STATUS_DRAFT) {
            return ApiResponse::error('Only a draft review cycle can be deleted. Close it instead.', 422);
        }

        $performanceCycle->delete();

        return ApiResponse::noContent();
    }

    public function settings(): JsonResponse
    {
        $this->authorize('viewAny', PerformanceCycle::class);

        return ApiResponse::success($this->settingsRow(PerformanceSetting::current()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $settings = PerformanceSetting::current();
        $this->authorize('update', $settings);

        $data = $request->validate([
            'kpi_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'goal_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'manager_weight' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        if ($data['kpi_weight'] + $data['goal_weight'] + $data['manager_weight'] !== 100) {
            throw ValidationException::withMessages(['kpi_weight' => 'The three weights must add up to 100.']);
        }

        $settings->update($data);

        return ApiResponse::success($this->settingsRow($settings));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PerformanceCycle $cycle): array
    {
        $required = $cycle === null ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date'],
            'self_assessment_due' => ['nullable', 'date'],
            'manager_assessment_due' => ['nullable', 'date'],
            'evaluation_form_id' => ['nullable', 'integer', Rule::exists('tenant.evaluation_forms', 'id')],
            'status' => ['sometimes', Rule::in(PerformanceCycle::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $start = $data['start_date'] ?? $cycle?->start_date?->toDateString();
        $end = $data['end_date'] ?? $cycle?->end_date?->toDateString();
        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(PerformanceCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'start_date' => $cycle->start_date?->toDateString(),
            'end_date' => $cycle->end_date?->toDateString(),
            'self_assessment_due' => $cycle->self_assessment_due?->toDateString(),
            'manager_assessment_due' => $cycle->manager_assessment_due?->toDateString(),
            'evaluation_form' => $cycle->form !== null ? ['id' => $cycle->form->id, 'name' => $cycle->form->name] : null,
            'status' => $cycle->status,
            'description' => $cycle->description,
            'goals_count' => $cycle->goals_count ?? null,
        ];
    }

    /** @return array<string, int> */
    private function settingsRow(PerformanceSetting $settings): array
    {
        return ['kpi_weight' => $settings->kpi_weight, 'goal_weight' => $settings->goal_weight, 'manager_weight' => $settings->manager_weight];
    }
}
