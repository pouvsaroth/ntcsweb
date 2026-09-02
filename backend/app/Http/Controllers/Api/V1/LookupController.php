<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\BaseData\LookupQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The reusable, read-only dropdown endpoint every module calls (Student
 * Gender, Book Type, Payment Method, ...). Deliberately NOT permission-gated
 * beyond being a signed-in tenant user — same precedent as GeographyController's
 * province/district/commune/village lookups: a teacher filling in a student's
 * profile needs Gender without holding a Base Data admin permission.
 *
 * Response shape is intentionally minimal ({id, code, name}) — see
 * LookupValueController for the full multi-language admin shape.
 */
final class LookupController extends Controller
{
    public function __construct(private readonly LookupQueryService $lookups) {}

    public function categories(): JsonResponse
    {
        return ApiResponse::success(
            $this->lookups->categories()->map(fn ($category) => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
            ])->values()
        );
    }

    public function values(Request $request, string $category): JsonResponse
    {
        return ApiResponse::success($this->lookups->values($category, $request->string('lang')->toString() ?: null));
    }

    public function show(Request $request, string $category, int $id): JsonResponse
    {
        $values = $this->lookups->values($category, $request->string('lang')->toString() ?: null);
        $match = collect($values)->firstWhere('id', $id);

        if ($match === null) {
            return ApiResponse::error('Lookup value not found.', 404);
        }

        return ApiResponse::success($match);
    }
}
