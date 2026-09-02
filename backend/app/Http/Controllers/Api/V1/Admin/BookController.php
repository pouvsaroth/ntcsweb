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

        $books = ApiQuery::for(Book::query()->with('programs')->withCount('classes'), $request)
            ->searchable('title', 'author', 'isbn')
            ->filterable(['status'])
            ->sortable(['title', 'author', 'created_at'], default: 'title')
            ->paginate();

        return ApiResponse::success(BookResource::collection($books));
    }

    public function store(StoreBookRequest $request): JsonResponse
    {
        $data = $request->validated();
        $programIds = $data['program_ids'] ?? [];
        unset($data['program_ids']);

        $book = Book::query()->create($data);
        $book->programs()->sync($programIds);

        return ApiResponse::created(new BookResource($book->load('programs')));
    }

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return ApiResponse::success(new BookResource($book->load('programs')->loadCount('classes')));
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResponse
    {
        $data = $request->validated();
        $programIds = $data['program_ids'] ?? null;
        unset($data['program_ids']);

        $book->update($data);

        if ($programIds !== null) {
            $book->programs()->sync($programIds);
        }

        return ApiResponse::success(new BookResource($book->load('programs')));
    }

    public function destroy(Book $book): JsonResponse
    {
        $this->authorize('delete', $book);

        $book->delete();

        return ApiResponse::noContent();
    }
}
