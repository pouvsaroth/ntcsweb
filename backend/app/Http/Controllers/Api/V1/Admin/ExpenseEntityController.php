<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreExpenseEntityRequest;
use App\Http\Requests\Api\V1\Admin\UpdateExpenseEntityRequest;
use App\Http\Resources\ExpenseEntityResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExpenseEntity;
use App\Models\ExpenseSupport;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Accounting > Expense for: who an expense is for (Newtech, Flexi, Family...). */
final class ExpenseEntityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpenseEntity::class);

        $entities = ApiQuery::for(ExpenseEntity::query()->withCount('expenses'), $request)
            ->searchable('name', 'description')
            ->filterable(['is_active'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(ExpenseEntityResource::collection($entities));
    }

    public function store(StoreExpenseEntityRequest $request): JsonResponse
    {
        $entity = ExpenseEntity::query()->create($request->validated());

        return ApiResponse::created(new ExpenseEntityResource($entity));
    }

    public function show(ExpenseEntity $expenseEntity): JsonResponse
    {
        $this->authorize('view', $expenseEntity);

        return ApiResponse::success(new ExpenseEntityResource($expenseEntity->loadCount('expenses')));
    }

    public function update(UpdateExpenseEntityRequest $request, ExpenseEntity $expenseEntity): JsonResponse
    {
        $expenseEntity->update($request->validated());

        return ApiResponse::success(new ExpenseEntityResource($expenseEntity));
    }

    public function destroy(ExpenseEntity $expenseEntity): JsonResponse
    {
        $this->authorize('delete', $expenseEntity);

        if ($expenseEntity->expenses()->exists() || ExpenseSupport::query()->where('expense_entity_id', $expenseEntity->id)->exists()) {
            return ApiResponse::error(__('This is used by expenses or support records and cannot be deleted. Deactivate it instead.'), 422);
        }

        $expenseEntity->delete();

        return ApiResponse::noContent();
    }
}
