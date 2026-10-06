<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\OrganizationUnitRequest;
use App\Http\Resources\OrganizationUnitResource;
use App\Http\Responses\ApiResponse;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CRUD shared by HRM > Organization Management's simple lists (Branch,
 * Team, Job grade, Job level) — each is just code/name/description/active
 * (Branch adds phone/address), so one controller serves them all and each
 * concrete subclass only names its model. Gated by OrganizationUnitPolicy.
 */
abstract class OrganizationUnitController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Relations a unit can't be deleted while it still has — e.g. a branch's departments or staff. */
    abstract protected function usedBy(): array;

    /** Relations loaded for each unit in the response (Team's department). */
    protected function with(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $class = $this->modelClass();
        $this->authorize('viewAny', $class);

        $units = ApiQuery::for($class::query()->with($this->with()), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['name', 'code', 'created_at'], default: 'code')
            ->paginate();

        return ApiResponse::success(OrganizationUnitResource::collection($units));
    }

    public function store(OrganizationUnitRequest $request): JsonResponse
    {
        $class = $this->modelClass();
        $this->authorize('create', $class);

        $unit = $class::query()->create($request->validated());

        return ApiResponse::created(new OrganizationUnitResource($unit->load($this->with())));
    }

    public function show(int $id): JsonResponse
    {
        $unit = $this->findOrFail($id);
        $this->authorize('view', $unit);

        return ApiResponse::success(new OrganizationUnitResource($unit->load($this->with())));
    }

    public function update(OrganizationUnitRequest $request, int $id): JsonResponse
    {
        $unit = $this->findOrFail($id);
        $this->authorize('update', $unit);

        $unit->update($request->validated());

        return ApiResponse::success(new OrganizationUnitResource($unit->load($this->with())));
    }

    public function destroy(int $id): JsonResponse
    {
        $unit = $this->findOrFail($id);
        $this->authorize('delete', $unit);

        foreach ($this->usedBy() as $relation) {
            if ($unit->{$relation}()->exists()) {
                return ApiResponse::error('This is in use and cannot be deleted. Deactivate it instead.', 422);
            }
        }

        $unit->delete();

        return ApiResponse::noContent();
    }

    private function findOrFail(int $id): Model
    {
        return $this->modelClass()::query()->findOrFail($id);
    }
}
