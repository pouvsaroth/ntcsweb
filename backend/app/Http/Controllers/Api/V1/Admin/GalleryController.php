<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreGalleryImageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateGalleryImageRequest;
use App\Http\Resources\GalleryImageResource;
use App\Http\Responses\ApiResponse;
use App\Models\GalleryImage;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class GalleryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GalleryImage::class);

        $images = ApiQuery::for(GalleryImage::query(), $request)
            ->filterable(['status'])
            ->sortable(['sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(GalleryImageResource::collection($images));
    }

    public function store(StoreGalleryImageRequest $request): JsonResponse
    {
        $path = $this->storeImage($request);

        $image = GalleryImage::query()->create([
            ...$request->safe()->except('image'),
            'image_path' => $path,
        ]);

        return ApiResponse::created(new GalleryImageResource($image));
    }

    public function show(GalleryImage $gallery): JsonResponse
    {
        $this->authorize('view', $gallery);

        return ApiResponse::success(new GalleryImageResource($gallery));
    }

    public function update(UpdateGalleryImageRequest $request, GalleryImage $gallery): JsonResponse
    {
        $previousPath = $gallery->image_path;
        $newPath = $request->hasFile('image') ? $this->storeImage($request) : null;

        $gallery->update([
            ...$request->safe()->except('image'),
            ...($newPath !== null ? ['image_path' => $newPath] : []),
        ]);

        // Only removed after the new path is safely persisted — if the update
        // above had failed, the old file must still be there to fall back on.
        if ($newPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return ApiResponse::success(new GalleryImageResource($gallery));
    }

    public function destroy(GalleryImage $gallery): JsonResponse
    {
        $this->authorize('delete', $gallery);

        // Soft-deleted only — GalleryImage::booted() removes the file itself
        // on a *force* delete, so a mistaken removal stays recoverable.
        $gallery->delete();

        return ApiResponse::noContent();
    }

    private function storeImage(StoreGalleryImageRequest|UpdateGalleryImageRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('image')->store($tenant->storagePath('gallery'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded image.');
        }

        return $path;
    }
}
