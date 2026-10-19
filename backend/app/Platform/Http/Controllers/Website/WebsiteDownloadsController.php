<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDownloadFileRequest;
use App\Http\Requests\Api\V1\Admin\StoreDownloadFolderRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDownloadFileRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDownloadFolderRequest;
use App\Http\Resources\DownloadFileResource;
use App\Http\Resources\DownloadFolderResource;
use App\Http\Responses\ApiResponse;
use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use App\Platform\Support\PlatformAudit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Back office → Website → Downloads: folders (one level of sub-folders) of
 * files for the public Download page (docs/backoffice/stage-7-website.md).
 * The school app's models and validation rules, inside EnterWebsiteSchool.
 */
final class WebsiteDownloadsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformAudit $audit,
    ) {}

    public function folders(): JsonResponse
    {
        $folders = DownloadFolder::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->ordered()->withCount('files')])
            ->withCount('files')
            ->ordered()
            ->get();

        return ApiResponse::success(DownloadFolderResource::collection($folders));
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate((new StoreDownloadFolderRequest)->rules());
        $folder = DownloadFolder::query()->create($data);
        $this->audit->record('website.download_folder.created', $folder, new: $data, tenantId: $this->context->id());

        return ApiResponse::created(new DownloadFolderResource($folder->loadCount('files')));
    }

    public function updateFolder(Request $request, string $id): JsonResponse
    {
        $folder = DownloadFolder::query()->findOrFail($id);
        $data = $request->validate((new UpdateDownloadFolderRequest)->rules());
        $old = $folder->only(array_keys($data));
        $folder->update($data);
        $this->audit->record('website.download_folder.updated', $folder, $old, $data, tenantId: $this->context->id());

        return ApiResponse::success(new DownloadFolderResource($folder->loadCount('files')));
    }

    public function destroyFolder(string $id): JsonResponse
    {
        $folder = DownloadFolder::query()->findOrFail($id);
        $folder->delete();
        $this->audit->record('website.download_folder.deleted', $folder, old: ['name' => $folder->name], tenantId: $this->context->id());

        return ApiResponse::noContent();
    }

    public function files(string $folderId): JsonResponse
    {
        $folder = DownloadFolder::query()->findOrFail($folderId);

        return ApiResponse::success(DownloadFileResource::collection($folder->files()->ordered()->get()));
    }

    public function storeFile(Request $request, string $folderId): JsonResponse
    {
        $folder = DownloadFolder::query()->findOrFail($folderId);
        $request->validate((new StoreDownloadFileRequest)->rules());

        $upload = $request->file('file');
        $path = $upload->store($this->context->getOrFail()->storagePath('downloads'), 'public');
        if ($path === false) {
            abort(500, 'Failed to store the uploaded file.');
        }

        $file = $folder->files()->create([
            'name' => $request->input('name') ?: pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME),
            'file_path' => $path,
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
        ]);
        $this->audit->record('website.download_file.created', $file, new: ['name' => $file->name, 'folder' => $folder->name], tenantId: $this->context->id());

        return ApiResponse::created(new DownloadFileResource($file));
    }

    public function updateFile(Request $request, string $id): JsonResponse
    {
        $file = DownloadFile::query()->findOrFail($id);
        $data = $request->validate((new UpdateDownloadFileRequest)->rules());
        $old = $file->only(array_keys($data));
        $file->update($data);
        $this->audit->record('website.download_file.updated', $file, $old, $data, tenantId: $this->context->id());

        return ApiResponse::success(new DownloadFileResource($file));
    }

    public function destroyFile(string $id): JsonResponse
    {
        $file = DownloadFile::query()->findOrFail($id);
        $file->delete();
        $this->audit->record('website.download_file.deleted', $file, old: ['name' => $file->name], tenantId: $this->context->id());

        return ApiResponse::noContent();
    }
}
