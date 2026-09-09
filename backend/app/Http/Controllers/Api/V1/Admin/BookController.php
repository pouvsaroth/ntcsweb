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

final class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Book::class);

        $books = ApiQuery::for(Book::query()->with(['academicProgram', 'bookCategory'])->withCount('classes'), $request)
            ->searchable('title', 'author', 'isbn')
            ->filterable(['status', 'academic_program_id'])
            ->sortable(['title', 'author', 'created_at'], default: 'title')
            ->paginate();

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

        return ApiResponse::success(new BookResource($book->load(['academicProgram', 'bookCategory'])->loadCount('classes')));
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
}
