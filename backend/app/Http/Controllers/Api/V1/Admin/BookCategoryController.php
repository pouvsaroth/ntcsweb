<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBookCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBookCategoryRequest;
use App\Http\Resources\BookCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\BookCategory;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BookCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BookCategory::class);

        $categories = ApiQuery::for(BookCategory::query()->with('academicProgram')->withCount('books'), $request)
            ->searchable('name')
            ->filterable(['is_active', 'academic_program_id'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(BookCategoryResource::collection($categories));
    }

    public function store(StoreBookCategoryRequest $request): JsonResponse
    {
        $category = BookCategory::query()->create($request->validated());

        return ApiResponse::created(new BookCategoryResource($category->load('academicProgram')));
    }

    public function show(BookCategory $bookCategory): JsonResponse
    {
        $this->authorize('view', $bookCategory);

        return ApiResponse::success(new BookCategoryResource($bookCategory->load('academicProgram')->loadCount('books')));
    }

    public function update(UpdateBookCategoryRequest $request, BookCategory $bookCategory): JsonResponse
    {
        $bookCategory->update($request->validated());

        return ApiResponse::success(new BookCategoryResource($bookCategory->load('academicProgram')));
    }

    public function destroy(BookCategory $bookCategory): JsonResponse
    {
        $this->authorize('delete', $bookCategory);

        $bookCategory->delete();

        return ApiResponse::noContent();
    }
}
