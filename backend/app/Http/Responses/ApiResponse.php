<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place that decides what an NTCSWEB API response looks like.
 *
 *   { "success": bool, "message": string|null, "data": mixed, "meta": object }
 *
 * Controllers return through here so the SPA can rely on the shape without
 * special-casing endpoints. Pagination metadata is flattened into `meta`
 * instead of Laravel's default nested `links`/`meta` pair, so a paginated
 * response and a plain one differ only by the presence of `meta.pagination`.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        [$data, $paginationMeta] = self::unwrap($data);

        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge($paginationMeta, $meta) ?: null,
        ], static fn ($value, $key) => $key === 'data' || $value !== null, ARRAY_FILTER_USE_BOTH), $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message ?? 'Created.', status: Response::HTTP_CREATED);
    }

    /**
     * 204 carries no body at all — an envelope in a No Content response is a
     * protocol violation that some HTTP clients reject.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    public static function error(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        ?string $code = null,
        array $errors = [],
    ): JsonResponse {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'error' => $code === null ? null : ['code' => $code],
            'errors' => $errors ?: null,
        ], static fn ($value) => $value !== null), $status);
    }

    /**
     * Field-level validation failures. 422 with `errors` keyed by input name,
     * which is what the SPA's form components bind to.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function validationError(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::error(
            $message,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'VALIDATION_FAILED',
            $errors,
        );
    }

    /**
     * Pull paginator metadata out to the top level so `data` is always just the
     * records.
     *
     * @return array{0: mixed, 1: array}
     */
    private static function unwrap(mixed $data): array
    {
        if ($data instanceof ResourceCollection) {
            $resource = $data->resource;

            if ($resource instanceof LengthAwarePaginator || $resource instanceof CursorPaginator || $resource instanceof Paginator) {
                return [$data->collection, ['pagination' => self::paginationMeta($resource)]];
            }

            return [$data->collection, []];
        }

        if ($data instanceof LengthAwarePaginator || $data instanceof CursorPaginator || $data instanceof Paginator) {
            return [$data->items(), ['pagination' => self::paginationMeta($data)]];
        }

        if ($data instanceof JsonResource) {
            return [$data, []];
        }

        return [$data, []];
    }

    private static function paginationMeta(mixed $paginator): array
    {
        if ($paginator instanceof CursorPaginator) {
            // No total: counting is what makes offset pagination collapse on
            // large tables, and cursor pagination exists precisely to avoid it.
            return [
                'type' => 'cursor',
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ];
        }

        if ($paginator instanceof LengthAwarePaginator) {
            return [
                'type' => 'length_aware',
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        return [
            'type' => 'simple',
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }
}
