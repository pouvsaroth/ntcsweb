<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GalleryImage;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public Gallery page. Unauthenticated — gated only by `tenant.required`
 * on the route group, same as every other public endpoint.
 *
 * Paginated (unlike HomeSlide's/Program's public `index()`, which return
 * their whole active set — a slider or course catalog is always small): a
 * school's gallery can hold far more photos, and the frontend
 * (`publicContentService.getGallery()`) already requests `page`/`per_page`.
 *
 * `{id, url, caption}`, not GalleryImageResource's `{id, image_url, ...}` —
 * the frontend's `GalleryImage` type was already built against this shape
 * before this backend existed; kept as-is rather than reshaped to match the
 * admin resource.
 */
final class GalleryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $images = ApiQuery::for(GalleryImage::query()->active(), $request)
            ->sortable(['sort_order'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success($images->through(fn (GalleryImage $image) => [
            'id' => $image->id,
            'url' => $image->imageUrl(),
            'caption' => $image->caption,
        ]));
    }

    /**
     * A plain `<img>`/`<a href>` pointing straight at the storage URL never
     * forces a save dialog for a cross-origin request (the frontend dev
     * server and this API are different origins — see vite.config.ts's
     * proxy) — the browser just navigates to it instead. Routing the
     * download through `/api/...` (which *is* proxied, and is naturally
     * same-origin in production) and sending the file back with
     * `Content-Disposition: attachment` is what actually triggers a download
     * regardless of origin.
     *
     * Not route-model-bound: `{id}` is resolved manually, after
     * `tenant.required`/`ResolveTenant` have already run, rather than risking
     * `BelongsToTenant`'s global scope applying during implicit binding
     * before the tenant is in context.
     */
    public function download(int $id): StreamedResponse
    {
        $image = GalleryImage::query()->active()->findOrFail($id);

        $extension = pathinfo($image->image_path, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = Str::slug($image->caption ?: 'photo').'.'.$extension;

        return Storage::disk('public')->download($image->image_path, $filename);
    }
}
