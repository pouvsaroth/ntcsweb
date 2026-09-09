<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBookRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Http\Responses\ApiResponse;
use App\Models\Book;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Book::class);

        $books = ApiQuery::for(Book::query()->with(['academicProgram', 'bookCategory']), $request)
            ->searchable('title', 'author', 'isbn')
            ->filterable(['status', 'academic_program_id'])
            ->sortable(['title', 'author', 'created_at'], default: 'title')
            ->paginate();

        $this->attachClassesCounts($books);

        return ApiResponse::success(BookResource::collection($books));
    }

    public function store(StoreBookRequest $request): JsonResponse
    {
        $book = Book::query()->create($request->validated());

        return ApiResponse::created(new BookResource($book->load(['academicProgram', 'bookCategory'])));
    }

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $book->load(['academicProgram', 'bookCategory']);
        $this->attachClassesCounts([$book]);

        return ApiResponse::success(new BookResource($book));
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResponse
    {
        $book->update($request->validated());

        return ApiResponse::success(new BookResource($book->load(['academicProgram', 'bookCategory'])));
    }

    public function destroy(Book $book): JsonResponse
    {
        $this->authorize('delete', $book);

        $book->delete();

        return ApiResponse::noContent();
    }

    /**
     * `classes` (still central) reaches a Book only through the `class_book`
     * pivot, which now lives in the tenant database alongside `books` — see
     * that migration's docblock. A BelongsToMany back to SchoolClass can no
     * longer run as one query (Book::classes() was removed for the same
     * reason), so this counts pivot rows directly instead.
     *
     * @param  iterable<Book>  $books
     */
    private function attachClassesCounts(iterable $books): void
    {
        // Not collect($books)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $books instanceof \Illuminate\Contracts\Pagination\Paginator ? $books->items() : (is_array($books) ? $books : iterator_to_array($books));

        if ($models === []) {
            return;
        }

        $counts = DB::connection('tenant')->table('class_book')
            ->whereIn('book_id', collect($models)->pluck('id'))
            ->select('book_id', DB::raw('COUNT(*) as total'))
            ->groupBy('book_id')
            ->pluck('total', 'book_id');

        foreach ($models as $book) {
            $book->setAttribute('classes_count', (int) ($counts[$book->id] ?? 0));
        }
    }
}
