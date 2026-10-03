<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDownloadFolderRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDownloadFolderRequest;
use App\Http\Resources\DownloadFolderResource;
use App\Http\Responses\ApiResponse;
use App\Models\DownloadFolder;
use Illuminate\Http\JsonResponse;

/**
 * The admin Upload menu's folders — see DownloadFolder. Listed as the whole
 * two-level tree at once: a school's folder list is small.
 */
final class DownloadFolderController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', DownloadFolder::class);

        $folders = DownloadFolder::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->ordered()->withCount('files')])
            ->withCount('files')
            ->ordered()
            ->get();

        return ApiResponse::success(DownloadFolderResource::collection($folders));
    }

    public function store(StoreDownloadFolderRequest $request): JsonResponse
    {
        $folder = DownloadFolder::query()->create($request->validated());

        return ApiResponse::created(new DownloadFolderResource($folder->loadCount('files')));
    }

    public function show(DownloadFolder $downloadFolder): JsonResponse
    {
        $this->authorize('view', $downloadFolder);

        return ApiResponse::success(new DownloadFolderResource($downloadFolder->loadCount('files')));
    }

    public function update(UpdateDownloadFolderRequest $request, DownloadFolder $downloadFolder): JsonResponse
    {
        $downloadFolder->update($request->validated());

        return ApiResponse::success(new DownloadFolderResource($downloadFolder->loadCount('files')));
    }

    /** Removes the folder with every sub-folder and file inside — see DownloadFolder::booted(). */
    public function destroy(DownloadFolder $downloadFolder): JsonResponse
    {
        $this->authorize('delete', $downloadFolder);

        $downloadFolder->delete();

        return ApiResponse::noContent();
    }
}
