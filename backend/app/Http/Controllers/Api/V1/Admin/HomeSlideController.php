<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreHomeSlideRequest;
use App\Http\Requests\Api\V1\Admin\UpdateHomeSlideRequest;
use App\Http\Resources\HomeSlideResource;
use App\Http\Responses\ApiResponse;
use App\Models\HomeSlide;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class HomeSlideController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HomeSlide::class);

        $slides = ApiQuery::for(HomeSlide::query(), $request)
            ->filterable(['status'])
            ->sortable(['sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(HomeSlideResource::collection($slides));
    }

    public function store(StoreHomeSlideRequest $request): JsonResponse
    {
        $path = $this->storeImage($request);

        $slide = HomeSlide::query()->create([
            ...$request->safe()->except('image'),
            'image_path' => $path,
        ]);

        return ApiResponse::created(new HomeSlideResource($slide));
    }

    public function show(HomeSlide $home_slide): JsonResponse
    {
        $this->authorize('view', $home_slide);

        return ApiResponse::success(new HomeSlideResource($home_slide));
    }

    public function update(UpdateHomeSlideRequest $request, HomeSlide $home_slide): JsonResponse
    {
        $previousPath = $home_slide->image_path;
        $newPath = $request->hasFile('image') ? $this->storeImage($request) : null;

        $home_slide->update([
            ...$request->safe()->except('image'),
            ...($newPath !== null ? ['image_path' => $newPath] : []),
        ]);

        // Only removed after the new path is safely persisted — if the update
        // above had failed, the old file must still be there to fall back on.
        if ($newPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return ApiResponse::success(new HomeSlideResource($home_slide));
    }

    public function destroy(HomeSlide $home_slide): JsonResponse
    {
        $this->authorize('delete', $home_slide);

        // Soft-deleted only — HomeSlide::booted() removes the file itself on
        // a *force* delete, so a mistaken removal stays recoverable.
        $home_slide->delete();

        return ApiResponse::noContent();
    }

    private function storeImage(StoreHomeSlideRequest|UpdateHomeSlideRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('image')->store($tenant->storagePath('home-slides'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded image.');
        }

        return $path;
    }
}
