<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAboutPageRequest;
use App\Http\Responses\ApiResponse;
use App\Support\Content\AboutPageContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * A singleton, not a REST resource — there is exactly one About page per
 * school, stored in `tenants.settings->about` (see AboutPageContent).
 */
final class AboutPageController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success(AboutPageContent::forTenant($tenant));
    }

    public function update(UpdateAboutPageRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('update', $tenant);

        // This is a read-modify-write on the same JSON column every other
        // setting also lives in — refresh right before reading it so a
        // `$tenant` instance resolved earlier in the request (or cached on
        // the authenticated user's `tenant()` relation) can never clobber a
        // setting saved elsewhere moments ago.
        $tenant->refresh();

        $data = $request->safe()->except('history_image');
        $existingAbout = $tenant->setting('about') ?? [];

        $imagePath = $existingAbout['history_image_path'] ?? null;

        if ($request->hasFile('history_image')) {
            $newPath = $request->file('history_image')->store($tenant->storagePath('about'), 'public');

            if ($newPath === false) {
                abort(500, 'Failed to store the uploaded image.');
            }

            // Only removed once the new image is safely persisted.
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            $imagePath = $newPath;
        }

        $tenant->update([
            'settings' => [
                ...($tenant->settings ?? []),
                'about' => [...$data, 'history_image_path' => $imagePath],
            ],
        ]);

        return ApiResponse::success(AboutPageContent::forTenant($tenant->fresh()));
    }
}
