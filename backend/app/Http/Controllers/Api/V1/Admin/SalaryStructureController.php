<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SalaryStructureRequest;
use App\Http\Resources\SalaryStructureResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalaryStructure;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** HRM > Payroll > Salary structure — reusable pay packages and their items. */
final class SalaryStructureController extends Controller
{
    private const WITH = ['items.component'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalaryStructure::class);

        $structures = ApiQuery::for(SalaryStructure::query()->with(self::WITH)->withCount('salaries'), $request)
            ->searchable('code', 'name')
            ->filterable(['currency', 'is_active'])
            ->sortable(['code', 'name'], default: 'code')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(SalaryStructureResource::collection($structures));
    }

    public function store(SalaryStructureRequest $request): JsonResponse
    {
        $this->authorize('create', SalaryStructure::class);

        $structure = DB::connection('tenant')->transaction(function () use ($request) {
            $structure = SalaryStructure::query()->create($request->safe()->except('items'));
            $this->syncItems($structure, $request->validated('items', []));

            return $structure;
        });

        return ApiResponse::created(new SalaryStructureResource($structure->load(self::WITH)->loadCount('salaries')));
    }

    public function show(SalaryStructure $salaryStructure): JsonResponse
    {
        $this->authorize('view', $salaryStructure);

        return ApiResponse::success(new SalaryStructureResource($salaryStructure->load(self::WITH)->loadCount('salaries')));
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $salaryStructure): JsonResponse
    {
        $this->authorize('update', $salaryStructure);

        $data = $request->safe()->except('items');

        // Staff on this structure have a salary in its currency (see
        // StaffSalaryRequest) — switching it would leave them mismatched.
        if (array_key_exists('currency', $data) && $data['currency'] !== $salaryStructure->currency && $salaryStructure->salaries()->exists()) {
            return ApiResponse::validationError(['currency' => ['Staff salaries use this structure, so its currency cannot change. Make a new structure instead.']]);
        }

        DB::connection('tenant')->transaction(function () use ($request, $salaryStructure, $data) {
            $salaryStructure->update($data);
            if ($request->has('items')) {
                $this->syncItems($salaryStructure, $request->validated('items', []));
            }
        });

        return ApiResponse::success(new SalaryStructureResource($salaryStructure->load(self::WITH)->loadCount('salaries')));
    }

    public function destroy(SalaryStructure $salaryStructure): JsonResponse
    {
        $this->authorize('delete', $salaryStructure);

        if ($salaryStructure->salaries()->exists()) {
            return ApiResponse::error('Staff salaries use this structure, so it cannot be deleted. Deactivate it instead.', 422);
        }

        $salaryStructure->delete();

        return ApiResponse::noContent();
    }

    /** @param  list<array{payroll_component_id:int, amount:numeric-string|float}>  $items */
    private function syncItems(SalaryStructure $structure, array $items): void
    {
        $keep = collect($items)->pluck('payroll_component_id')->map(fn ($id) => (int) $id)->all();
        $structure->items()->whereNotIn('payroll_component_id', $keep)->delete();

        foreach ($items as $item) {
            $structure->items()->updateOrCreate(
                ['payroll_component_id' => (int) $item['payroll_component_id']],
                ['amount' => $item['amount']],
            );
        }
    }
}
