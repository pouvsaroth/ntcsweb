<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreGalleryImageRequest;
use App\Http\Requests\Api\V1\Admin\StoreHomeSlideRequest;
use App\Http\Requests\Api\V1\Admin\StorePromotionRequest;
use App\Http\Requests\Api\V1\Admin\UpdateGalleryImageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateHomeSlideRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePromotionRequest;
use App\Http\Resources\GalleryImageResource;
use App\Http\Responses\ApiResponse;
use App\Http\Resources\HomeSlideResource;
use App\Http\Resources\PromotionResource;
use App\Models\GalleryImage;
use App\Models\HomeSlide;
use App\Models\Promotion;
use App\Platform\Support\PlatformAudit;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Back office → Website: the three "a picture and a few fields" lists —
 * home slides, gallery, promotions (docs/backoffice/stage-7-website.md).
 * One controller, configured per kind; the models, validation rules and
 * response shapes are the school app's own, so the public website reads
 * exactly what it always has. Runs inside EnterWebsiteSchool.
 */
final class WebsiteImagesController extends Controller
{
    /**
     * @var array<string, array{model: class-string<Model>, resource: class-string, store: class-string, update: class-string, dir: string}>
     */
    private const KINDS = [
        'slides' => ['model' => HomeSlide::class, 'resource' => HomeSlideResource::class, 'store' => StoreHomeSlideRequest::class, 'update' => UpdateHomeSlideRequest::class, 'dir' => 'home-slides'],
        'gallery' => ['model' => GalleryImage::class, 'resource' => GalleryImageResource::class, 'store' => StoreGalleryImageRequest::class, 'update' => UpdateGalleryImageRequest::class, 'dir' => 'gallery'],
        'promotions' => ['model' => Promotion::class, 'resource' => PromotionResource::class, 'store' => StorePromotionRequest::class, 'update' => UpdatePromotionRequest::class, 'dir' => 'promotions'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$kind, $k] = $this->kind($request);

        $page = ApiQuery::for($k['model']::query(), $request)
            ->filterable(['status'])
            ->sortable(['sort_order', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success($k['resource']::collection($page));
    }

    public function store(Request $request): JsonResponse
    {
        [$kind, $k] = $this->kind($request);
        $data = $request->validate((new $k['store'])->rules());

        $item = $k['model']::query()->create([
            ...collect($data)->except('image')->all(),
            'image_path' => $this->storeImage($request, $k['dir']),
        ]);

        $this->audit->record("website.{$kind}.created", $item, new: collect($data)->except('image')->all(), tenantId: $this->context->id());

        return ApiResponse::created(new $k['resource']($item));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$kind, $k] = $this->kind($request);

        return ApiResponse::success(new $k['resource']($k['model']::query()->findOrFail($id)));
    }

    /** Sent as a POST with `_method=PUT`: PHP only reads uploaded files from a POST. */
    public function update(Request $request, string $id): JsonResponse
    {
        [$kind, $k] = $this->kind($request);
        $item = $k['model']::query()->findOrFail($id);
        $data = $request->validate((new $k['update'])->rules());

        $fields = collect($data)->except('image')->all();
        $old = $item->only(array_keys($fields));
        $previousPath = $item->image_path;
        $newPath = $request->hasFile('image') ? $this->storeImage($request, $k['dir']) : null;

        $item->update([...$fields, ...($newPath !== null ? ['image_path' => $newPath] : [])]);

        // Only removed once the new path is saved, so a failed update still has its picture.
        if ($newPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        $this->audit->record("website.{$kind}.updated", $item, $old, $fields + ($newPath !== null ? ['image' => 'replaced'] : []), tenantId: $this->context->id());

        return ApiResponse::success(new $k['resource']($item));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        [$kind, $k] = $this->kind($request);
        $item = $k['model']::query()->findOrFail($id);

        // Soft-deleted only, as in the school app: the model removes the file on a *force* delete.
        $item->delete();
        $this->audit->record("website.{$kind}.deleted", $item, tenantId: $this->context->id());

        return ApiResponse::noContent();
    }

    /**
     * Which list this route is for — set as a route default in routes/platform.php.
     *
     * @return array{0: string, 1: array{model: class-string<Model>, resource: class-string, store: class-string, update: class-string, dir: string}}
     */
    private function kind(Request $request): array
    {
        $kind = (string) $request->route()?->defaults['kind'];

        return [$kind, self::KINDS[$kind] ?? abort(404)];
    }

    private function storeImage(Request $request, string $dir): string
    {
        $path = $request->file('image')->store($this->context->getOrFail()->storagePath($dir), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded image.');
        }

        return $path;
    }
}
