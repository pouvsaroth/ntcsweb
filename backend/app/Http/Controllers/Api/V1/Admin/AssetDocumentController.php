<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAssetDocumentRequest;
use App\Http\Resources\AssetDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/** Reuses the existing `public` disk convention — see ExpenseController::storeAttachment()'s identical pattern. */
final class AssetDocumentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return ApiResponse::success(AssetDocumentResource::collection(
            $asset->documents()->with('uploadedBy')->latest()->get()
        ));
    }

    public function store(StoreAssetDocumentRequest $request, Asset $asset): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();
        $file = $request->file('file');

        $path = $file->store($tenant->storagePath('asset-documents'), 'public');

        $document = AssetDocument::query()->create([
            'asset_id' => $asset->getKey(),
            'type' => $request->validated('type') ?? AssetDocument::OTHER,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'caption' => $request->validated('caption'),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created(new AssetDocumentResource($document->load('uploadedBy')));
    }

    public function destroy(Asset $asset, AssetDocument $document): JsonResponse
    {
        $this->authorize('update', $asset);
        abort_unless($document->asset_id === $asset->getKey(), 404);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return ApiResponse::noContent();
    }
}
