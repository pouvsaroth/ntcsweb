<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePromotionRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Promotion;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class PromotionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Promotion::class);

        $promotions = ApiQuery::for(Promotion::query(), $request)
            ->filterable(['status'])
            ->sortable(['sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(PromotionResource::collection($promotions));
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $path = $this->storeImage($request);

        $promotion = Promotion::query()->create([
            ...$request->safe()->except('image'),
            'image_path' => $path,
        ]);

        return ApiResponse::created(new PromotionResource($promotion));
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $this->authorize('view', $promotion);

        return ApiResponse::success(new PromotionResource($promotion));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $previousPath = $promotion->image_path;
        $newPath = $request->hasFile('image') ? $this->storeImage($request) : null;

        $promotion->update([
            ...$request->safe()->except('image'),
            ...($newPath !== null ? ['image_path' => $newPath] : []),
        ]);

        // Only removed after the new path is safely persisted — if the update
        // above had failed, the old file must still be there to fall back on.
        if ($newPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return ApiResponse::success(new PromotionResource($promotion));
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);

        // Soft-deleted only — Promotion::booted() removes the file itself
        // on a *force* delete, so a mistaken removal stays recoverable.
        $promotion->delete();

        return ApiResponse::noContent();
    }

    private function storeImage(StorePromotionRequest|UpdatePromotionRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('image')->store($tenant->storagePath('promotions'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded image.');
        }

        return $path;
    }
}
