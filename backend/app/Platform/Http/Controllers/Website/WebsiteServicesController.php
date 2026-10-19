<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Support\PlatformAudit;
use App\Support\Content\ServicesPageContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Back office → Website → Services page: the switch that makes the website
 * open on a "group of services" page, its two cards and the School
 * Management System product page (ServicesPageContent). Inside EnterWebsiteSchool.
 */
final class WebsiteServicesController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformAudit $audit,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(ServicesPageContent::forTenant($this->context->getOrFail()));
    }

    public function update(Request $request): JsonResponse
    {
        // Sent as multipart form data, where a boolean arrives as "1"/"0"/"true".
        if ($request->has('enabled')) {
            $request->merge(['enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN)]);
        }
        $data = $request->validate(ServicesPageContent::rules());

        $tenant = $this->context->getOrFail()->refresh();
        $existing = $tenant->setting('services') ?? [];
        $services = ['enabled' => (bool) $data['enabled']];
        $replaced = [];

        foreach (ServicesPageContent::CARDS as $card) {
            $old = $existing[$card] ?? [];
            $fields = [];
            foreach (ServicesPageContent::textFields($card) as $field) {
                foreach (['km', 'en'] as $lang) {
                    $fields["{$field}_{$lang}"] = (string) ($data[$card]["{$field}_{$lang}"] ?? '');
                }
            }
            if ($card === 'sms') {
                $fields['sign_in_url'] = (string) ($data['sms']['sign_in_url'] ?? '');
            }

            $imagePath = $old['image_path'] ?? null;
            if ($request->hasFile("{$card}_image")) {
                $newPath = $request->file("{$card}_image")->store($tenant->storagePath('services'), 'public');
                if ($newPath === false) {
                    abort(500, 'Failed to store the uploaded image.');
                }
                if ($imagePath !== null) {
                    $replaced[] = $imagePath;
                }
                $imagePath = $newPath;
            }

            $services[$card] = [...$fields, 'image_path' => $imagePath];
        }

        $tenant->update(['settings' => [...($tenant->settings ?? []), 'services' => $services]]);

        // Only once the new paths are saved, so a failed save still has its pictures.
        foreach ($replaced as $path) {
            Storage::disk('public')->delete($path);
        }

        $this->audit->record('website.services.updated', $tenant, ['enabled' => (bool) ($existing['enabled'] ?? false)], ['enabled' => $services['enabled']], tenantId: $tenant->id);

        return ApiResponse::success(ServicesPageContent::forTenant($tenant->fresh()));
    }
}
