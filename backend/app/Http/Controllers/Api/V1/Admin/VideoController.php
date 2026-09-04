<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreVideoRequest;
use App\Http\Requests\Api\V1\Admin\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Http\Responses\ApiResponse;
use App\Models\Video;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class VideoController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Video::class);

        $videos = ApiQuery::for(Video::query()->with('coursePackage'), $request)
            ->searchable('title', 'description')
            ->filterable(['status', 'course_package_id'])
            ->sortable(['title', 'sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(VideoResource::collection($videos));
    }

    public function store(StoreVideoRequest $request): JsonResponse
    {
        $video = Video::query()->create([
            ...$request->safe()->except('thumbnail'),
            'thumbnail_path' => $request->hasFile('thumbnail') ? $this->storeThumbnail($request) : null,
        ]);

        return ApiResponse::created(new VideoResource($video->load('coursePackage')));
    }

    public function show(Video $video): JsonResponse
    {
        $this->authorize('view', $video);

        return ApiResponse::success(new VideoResource($video->load('coursePackage')));
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        $previousPath = $video->thumbnail_path;
        $newPath = $request->hasFile('thumbnail') ? $this->storeThumbnail($request) : null;

        $video->update([
            ...$request->safe()->except('thumbnail'),
            ...($newPath !== null ? ['thumbnail_path' => $newPath] : []),
        ]);

        if ($newPath !== null && $previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return ApiResponse::success(new VideoResource($video->fresh()->load('coursePackage')));
    }

    public function destroy(Video $video): JsonResponse
    {
        $this->authorize('delete', $video);

        // Soft-deleted only — Video::booted() removes the thumbnail file
        // itself on a *force* delete, so a mistaken removal stays
        // recoverable. The YouTube video itself is untouched either way.
        $video->delete();

        return ApiResponse::noContent();
    }

    private function storeThumbnail(StoreVideoRequest|UpdateVideoRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('thumbnail')->store($tenant->storagePath('videos'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded thumbnail.');
        }

        return $path;
    }
}
