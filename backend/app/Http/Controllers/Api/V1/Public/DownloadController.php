<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\DownloadFileResource;
use App\Http\Responses\ApiResponse;
use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

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
    /** Keep in step with MAX_ZIP_FILES / MAX_ZIP_BYTES in frontend/src/services/downloads.ts. */
    public const MAX_ZIP_FILES = 100;

    public const MAX_ZIP_BYTES = 300 * 1024 * 1024;

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

    /**
     * Several of one folder's files in a single .zip — `?files=1,2,3`, or the
     * whole folder when `files` is left out. A GET, so the browser saves it
     * natively (phones included). IDs outside this folder are ignored.
     */
    public function zip(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $folder = $this->publicFolder($id);

        $query = DownloadFile::query()->where('download_folder_id', $folder->id)->ordered();
        if ($request->filled('files')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('files'))), fn (int $v) => $v > 0));
            $query->whereIn('id', $ids);
        }
        $files = $query->get();

        if ($files->isEmpty()) {
            return ApiResponse::error(__('No files to download.'), 422);
        }
        if ($files->count() > self::MAX_ZIP_FILES || $files->sum('size') > self::MAX_ZIP_BYTES) {
            return ApiResponse::error(__('Too many files for one download — choose fewer.'), 422);
        }

        $disk = Storage::disk('public');
        $zipPath = tempnam(sys_get_temp_dir(), 'dl-zip-');
        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500);

        $used = [];
        foreach ($files as $file) {
            if (! $disk->exists($file->file_path)) {
                continue;
            }
            $entry = $this->uniqueEntryName($file->downloadName(), $used);
            $zip->addFile($disk->path($file->file_path), $entry);
            // Pictures and PDFs barely shrink, so store rather than spend time compressing.
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
        $added = $zip->numFiles;
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);

            return ApiResponse::error(__('No files to download.'), 422);
        }

        $zipName = (Str::slug($folder->name) ?: 'download').'.zip';

        return response()->download($zipPath, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Two files can share a name; a zip can't — the second becomes "Name (2).ext".
     *
     * @param  array<string, true>  $used
     */
    private function uniqueEntryName(string $name, array &$used): string
    {
        $candidate = $name;
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        for ($n = 2; isset($used[strtolower($candidate)]); $n++) {
            $candidate = $extension !== '' ? "{$base} ({$n}).{$extension}" : "{$base} ({$n})";
        }
        $used[strtolower($candidate)] = true;

        return $candidate;
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
