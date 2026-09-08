<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBookCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBookCategoryRequest;
use App\Http\Resources\BookCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Models\BookCategory;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BookCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BookCategory::class);

        $categories = ApiQuery::for(BookCategory::query()->with('academicProgram'), $request)
            ->searchable('name')
            ->filterable(['is_active', 'academic_program_id'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        $this->attachBookCounts($categories);

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

        $bookCategory->load('academicProgram');
        $this->attachBookCounts([$bookCategory]);

        return ApiResponse::success(new BookCategoryResource($bookCategory));
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

    /**
     * `book_categories` lives in the tenant database while `books` is still
     * central, so `books_count` can no longer come from
     * withCount()/loadCount() (a single cross-database subquery) — it's
     * resolved as a separate query and attached manually, the shape
     * BookCategoryResource expects via whenCounted().
     *
     * @param  iterable<BookCategory>  $categories
     */
    private function attachBookCounts(iterable $categories): void
    {
        // Not collect($categories)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $categories instanceof \Illuminate\Contracts\Pagination\Paginator ? $categories->items() : (is_array($categories) ? $categories : iterator_to_array($categories));

        if ($models === []) {
            return;
        }

        $counts = Book::query()
            ->whereIn('book_category_id', collect($models)->pluck('id'))
            ->select('book_category_id', DB::raw('COUNT(*) as total'))
            ->groupBy('book_category_id')
            ->pluck('total', 'book_category_id');

        foreach ($models as $category) {
            $category->setAttribute('books_count', (int) ($counts[$category->id] ?? 0));
        }
    }
}
