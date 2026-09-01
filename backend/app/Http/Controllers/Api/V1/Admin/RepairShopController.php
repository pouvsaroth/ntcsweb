<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRepairShopRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRepairShopRequest;
use App\Http\Resources\RepairShopResource;
use App\Http\Responses\ApiResponse;
use App\Models\RepairShop;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RepairShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RepairShop::class);

        $shops = ApiQuery::for(RepairShop::query(), $request)
            ->searchable('name', 'contact_person', 'email', 'phone', 'specialization')
            ->filterable(['is_active'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(RepairShopResource::collection($shops));
    }

    public function store(StoreRepairShopRequest $request): JsonResponse
    {
        $shop = RepairShop::query()->create($request->validated());

        return ApiResponse::created(new RepairShopResource($shop));
    }

    public function show(RepairShop $repairShop): JsonResponse
    {
        $this->authorize('view', $repairShop);

        return ApiResponse::success(new RepairShopResource($repairShop));
    }

    public function update(UpdateRepairShopRequest $request, RepairShop $repairShop): JsonResponse
    {
        $repairShop->update($request->validated());

        return ApiResponse::success(new RepairShopResource($repairShop));
    }

    public function destroy(RepairShop $repairShop): JsonResponse
    {
        $this->authorize('delete', $repairShop);

        if ($repairShop->repairs()->exists() || $repairShop->maintenances()->exists()) {
            return ApiResponse::error('This repair shop is in use and cannot be deleted. Deactivate it instead.', 422);
        }

        $repairShop->delete();

        return ApiResponse::noContent();
    }
}
