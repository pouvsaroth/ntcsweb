<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\DownloadFileResource;
use App\Http\Responses\ApiResponse;
use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public Download page. Unauthenticated — gated only by
 * `tenant.required` on the route group, same as PublicGalleryController. A
 * hidden folder hides its sub-folders and files too.
 *
 * `{id}`s are resolved manually rather than route-model-bound, for the same
 * reason as PublicGalleryController::download().
 */
final class DownloadController extends Controller
{
    /** The active top folders with their active sub-folders, and how many files each holds. */
    public function index(): JsonResponse
    {
        $folders = DownloadFolder::query()
            ->whereNull('parent_id')
            ->active()
            ->with(['children' => fn ($query) => $query->active()->ordered()->withCount('files')])
            ->withCount('files')
            ->ordered()
            ->get();

        return ApiResponse::success($folders->map(fn (DownloadFolder $folder) => [
            ...$this->folderSummary($folder),
            'children' => $folder->children->map(fn (DownloadFolder $child) => $this->folderSummary($child))->values(),
        ])->values());
    }

    /** One folder's sub-folders and files. */
    public function show(int $id): JsonResponse
    {
        $folder = $this->publicFolder($id);
        $folder->load([
            'children' => fn ($query) => $query->active()->ordered()->withCount('files'),
            'files' => fn ($query) => $query->ordered(),
        ]);

        return ApiResponse::success([
            'id' => $folder->id,
            'name' => $folder->name,
            'parent' => $folder->parent !== null ? ['id' => $folder->parent->id, 'name' => $folder->parent->name] : null,
            'children' => $folder->children->map(fn (DownloadFolder $child) => $this->folderSummary($child))->values(),
            'files' => DownloadFileResource::collection($folder->files),
        ]);
    }

    /** Sent as an attachment through the API — see PublicGalleryController::download() for why. */
    public function download(int $id): StreamedResponse
    {
        $file = DownloadFile::query()->findOrFail($id);
        $this->publicFolder($file->download_folder_id);

        return Storage::disk('public')->download($file->file_path, $file->downloadName());
    }

    private function publicFolder(int $id): DownloadFolder
    {
        $folder = DownloadFolder::query()->with('parent')->findOrFail($id);
        abort_unless($folder->isPublic(), 404);

        return $folder;
    }

    /** @return array{id:int, name:string, files_count:int} */
    private function folderSummary(DownloadFolder $folder): array
    {
        return ['id' => $folder->id, 'name' => $folder->name, 'files_count' => (int) $folder->files_count];
    }
}
