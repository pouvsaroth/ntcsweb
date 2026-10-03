<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDownloadFileRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDownloadFileRequest;
use App\Http\Resources\DownloadFileResource;
use App\Http\Responses\ApiResponse;
use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** The files inside one Upload-menu folder — see DownloadFile. */
final class DownloadFileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(DownloadFolder $downloadFolder): JsonResponse
    {
        $this->authorize('view', $downloadFolder);

        return ApiResponse::success(DownloadFileResource::collection($downloadFolder->files()->ordered()->get()));
    }

    public function store(StoreDownloadFileRequest $request, DownloadFolder $downloadFolder): JsonResponse
    {
        $upload = $request->file('file');
        $path = $upload->store($this->context->getOrFail()->storagePath('downloads'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded file.');
        }

        // Defaults to the uploaded file's own name, without its extension
        // (DownloadFile::downloadName() adds it back on download).
        $name = $request->validated('name') ?: pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);

        $file = $downloadFolder->files()->create([
            'name' => $name,
            'file_path' => $path,
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
        ]);

        return ApiResponse::created(new DownloadFileResource($file));
    }

    public function update(UpdateDownloadFileRequest $request, DownloadFile $downloadFile): JsonResponse
    {
        $downloadFile->update($request->validated());

        return ApiResponse::success(new DownloadFileResource($downloadFile));
    }

    public function destroy(DownloadFile $downloadFile): JsonResponse
    {
        $this->authorize('delete', $downloadFile->folder);

        $downloadFile->delete();

        return ApiResponse::noContent();
    }
}
