<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLookupValueRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLookupValueRequest;
use App\Http\Resources\LookupValueResource;
use App\Http\Responses\ApiResponse;
use App\Models\LookupValue;
use App\Services\BaseData\LookupValueService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management shape — every configured language's translation, editable
 * in one request (see LookupValueResource/LookupValueService). The public,
 * lightweight {id, code, name} dropdown shape lives on LookupController
 * instead.
 */
final class LookupValueController extends Controller
{
    private const WITH = ['category', 'translations.language'];

    public function __construct(private readonly LookupValueService $values) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LookupValue::class);

        $values = ApiQuery::for(LookupValue::query()->with(self::WITH), $request)
            ->filterable(['is_active', 'lookup_category_id'])
            ->sortable(['sort_order', 'code', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(LookupValueResource::collection($values));
    }

    public function store(StoreLookupValueRequest $request): JsonResponse
    {
        $value = $this->values->create($request->validated(), $request->user());

        return ApiResponse::created(new LookupValueResource($value));
    }

    public function show(LookupValue $lookupValue): JsonResponse
    {
        $this->authorize('view', $lookupValue);

        return ApiResponse::success(new LookupValueResource($lookupValue->load(self::WITH)));
    }

    public function update(UpdateLookupValueRequest $request, LookupValue $lookupValue): JsonResponse
    {
        $value = $this->values->update($lookupValue, $request->validated(), $request->user());

        return ApiResponse::success(new LookupValueResource($value));
    }

    public function destroy(LookupValue $lookupValue): JsonResponse
    {
        $this->authorize('delete', $lookupValue);

        $this->values->delete($lookupValue);

        return ApiResponse::noContent();
    }
}
