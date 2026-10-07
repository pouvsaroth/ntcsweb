<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Kpi;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HRM > Performance Management > KPI — the KPI library. */
final class KpiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Kpi::class);

        $kpis = ApiQuery::for(Kpi::query()->with(['department', 'position']), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active', 'department_id', 'position_id'])
            ->sortable(['code', 'name'], default: 'code')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($kpis->through(fn (Kpi $kpi) => $this->row($kpi)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Kpi::class);

        $kpi = Kpi::query()->create($this->validated($request, null));

        return ApiResponse::created($this->row($kpi->load(['department', 'position'])));
    }

    public function update(Request $request, Kpi $kpi): JsonResponse
    {
        $this->authorize('update', $kpi);

        $kpi->update($this->validated($request, $kpi));

        return ApiResponse::success($this->row($kpi->load(['department', 'position'])));
    }

    public function destroy(Kpi $kpi): JsonResponse
    {
        $this->authorize('delete', $kpi);

        $kpi->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Kpi $kpi): array
    {
        $required = $kpi === null ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.kpis', 'code')->ignore($kpi?->id)],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'measurement' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'target' => ['nullable', 'numeric', 'min:-9999999999', 'max:9999999999'],
            'higher_is_better' => ['sometimes', 'boolean'],
            'default_weight' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('tenant.positions', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Kpi $kpi): array
    {
        return [
            'id' => $kpi->id,
            'code' => $kpi->code,
            'name' => $kpi->name,
            'description' => $kpi->description,
            'measurement' => $kpi->measurement,
            'unit' => $kpi->unit,
            'target' => $kpi->target,
            'higher_is_better' => $kpi->higher_is_better,
            'default_weight' => $kpi->default_weight,
            'department' => $kpi->department !== null ? ['id' => $kpi->department->id, 'name' => $kpi->department->name] : null,
            'position' => $kpi->position !== null ? ['id' => $kpi->position->id, 'name' => $kpi->position->name] : null,
            'is_active' => $kpi->is_active,
        ];
    }
}
