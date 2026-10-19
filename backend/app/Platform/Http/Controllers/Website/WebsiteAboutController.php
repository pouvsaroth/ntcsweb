<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAboutPageRequest;
use App\Http\Responses\ApiResponse;
use App\Platform\Support\PlatformAudit;
use App\Support\Content\AboutPageContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Back office → Website: which school's website this is, and its About page
 * (`tenants.settings->about`, AboutPageContent) — docs/backoffice/stage-7-website.md.
 */
final class WebsiteAboutController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformAudit $audit,
    ) {}

    /** The school whose website the menu edits, and where it is on the web. */
    public function summary(): JsonResponse
    {
        $school = $this->context->getOrFail()->loadMissing('primaryDomain');

        return ApiResponse::success([
            'id' => $school->id,
            'name' => $school->name,
            'name_en' => $school->name_en,
            'slug' => $school->slug,
            'hostname' => $school->hostname(),
        ]);
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success(AboutPageContent::forTenant($this->context->getOrFail()));
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->context->getOrFail()->refresh();
        $request->validate((new UpdateAboutPageRequest)->rules());

        $data = collect($request->only(['history_title', 'history_paragraph_1', 'history_paragraph_2', 'stats', 'pillars', 'achievements_title', 'achievements']))
            ->map(fn ($v) => $v ?? '')
            ->all();
        $existing = $tenant->setting('about') ?? [];
        $imagePath = $existing['history_image_path'] ?? null;
        $replaced = null;

        if ($request->hasFile('history_image')) {
            $newPath = $request->file('history_image')->store($tenant->storagePath('about'), 'public');
            if ($newPath === false) {
                abort(500, 'Failed to store the uploaded image.');
            }
            $replaced = $imagePath;
            $imagePath = $newPath;
        }

        $tenant->update(['settings' => [...($tenant->settings ?? []), 'about' => [...$data, 'history_image_path' => $imagePath]]]);
        if ($replaced !== null) {
            Storage::disk('public')->delete($replaced);
        }

        $this->audit->record('website.about.updated', $tenant, new: ['history_title' => $data['history_title'] ?? ''], tenantId: $tenant->id);

        return ApiResponse::success(AboutPageContent::forTenant($tenant->fresh()));
    }
}
