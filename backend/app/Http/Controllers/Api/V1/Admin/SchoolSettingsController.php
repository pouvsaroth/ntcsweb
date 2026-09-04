<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSchoolSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * A singleton, not a REST resource — same shape as AboutPageController, but
 * writing straight to the tenants table's own columns (name/email/phone/
 * address/logo) rather than the `settings` jsonb blob, since these already
 * exist as real columns. This is what feeds the public site's header/footer
 * branding — see Public\SiteSettingsController.
 */
final class SchoolSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success($this->present($tenant));
    }

    public function update(UpdateSchoolSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('update', $tenant);

        $data = $request->safe()->except(['logo', 'khqr_template']);
        $logoPath = $tenant->logo;

        if ($request->hasFile('logo')) {
            $newPath = $request->file('logo')->store($tenant->storagePath('branding'), 'public');

            if ($newPath === false) {
                abort(500, 'Failed to store the uploaded logo.');
            }

            // Only removed once the new logo is safely persisted.
            if ($logoPath !== null) {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = $newPath;
        }

        $tenant->update([
            ...$data,
            'logo' => $logoPath,
            'settings' => [...($tenant->settings ?? []), 'khqr_template' => $request->safe()->input('khqr_template')],
        ]);

        return ApiResponse::success($this->present($tenant->fresh()));
    }

    private function present(Tenant $tenant): array
    {
        return [
            'name' => $tenant->name,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'address' => $tenant->address,
            'locale' => $tenant->locale,
            'logo_url' => $tenant->logoUrl(),
            'khqr_template' => $tenant->khqrTemplate(),
        ];
    }
}
