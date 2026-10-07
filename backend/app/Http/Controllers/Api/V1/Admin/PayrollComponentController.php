<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\PayrollComponentRequest;
use App\Http\Resources\PayrollComponentResource;
use App\Http\Responses\ApiResponse;
use App\Models\PayrollComponent;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HRM > Payroll > Allowances / Bonuses / Deductions — the pay component types (`filter[kind]`). */
final class PayrollComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollComponent::class);

        $components = ApiQuery::for(PayrollComponent::query()->withCount(['staffAssignments', 'structureItems']), $request)
            ->searchable('code', 'name')
            ->filterable(['kind', 'is_active'])
            ->sortable(['code', 'name'], default: 'code')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(PayrollComponentResource::collection($components));
    }

    public function store(PayrollComponentRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollComponent::class);

        $component = PayrollComponent::query()->create($request->validated());

        return ApiResponse::created(new PayrollComponentResource($component->loadCount(['staffAssignments', 'structureItems'])));
    }

    public function show(PayrollComponent $payrollComponent): JsonResponse
    {
        $this->authorize('view', $payrollComponent);

        return ApiResponse::success(new PayrollComponentResource($payrollComponent->loadCount(['staffAssignments', 'structureItems'])));
    }

    public function update(PayrollComponentRequest $request, PayrollComponent $payrollComponent): JsonResponse
    {
        $this->authorize('update', $payrollComponent);

        $payrollComponent->update($request->validated());

        return ApiResponse::success(new PayrollComponentResource($payrollComponent->loadCount(['staffAssignments', 'structureItems'])));
    }

    public function destroy(PayrollComponent $payrollComponent): JsonResponse
    {
        $this->authorize('delete', $payrollComponent);

        if ($payrollComponent->structureItems()->exists() || $payrollComponent->staffAssignments()->exists()) {
            return ApiResponse::error('This item is used by a salary structure or given to staff and cannot be deleted. Deactivate it instead.', 422);
        }

        $payrollComponent->delete();

        return ApiResponse::noContent();
    }
}
