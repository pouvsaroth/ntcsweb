<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreFormCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateFormCategoryRequest;
use App\Http\Resources\FormCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\FormCategory;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * index() is deliberately unguarded by a permission — every authenticated
 * user browses this catalog to submit a request from the Forms page (see
 * FormCategoryPolicy's docblock). Only create/update/delete require
 * form-categories.manage.
 */
final class FormCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = ApiQuery::for(FormCategory::query(), $request)
            ->sortable(['order', 'name', 'created_at'], default: 'order')
            ->paginate();

        return ApiResponse::success(FormCategoryResource::collection($categories));
    }

    public function store(StoreFormCategoryRequest $request): JsonResponse
    {
        $category = FormCategory::query()->create($request->validated());

        return ApiResponse::created(new FormCategoryResource($category));
    }

    public function show(FormCategory $formCategory): JsonResponse
    {
        return ApiResponse::success(new FormCategoryResource($formCategory));
    }

    public function update(UpdateFormCategoryRequest $request, FormCategory $formCategory): JsonResponse
    {
        $formCategory->update($request->validated());

        return ApiResponse::success(new FormCategoryResource($formCategory));
    }

    public function destroy(FormCategory $formCategory): JsonResponse
    {
        $this->authorize('delete', $formCategory);

        $formCategory->delete();

        return ApiResponse::noContent();
    }
}
