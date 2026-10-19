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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Back office → Website → Services page: the switch that makes the website
 * open on a "group of services" page, and its cards — each opening its own
 * page, or (one card) the Computer School. ServicesPageContent has the
 * shape. Inside EnterWebsiteSchool.
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

    /** The switch alone; it applies at once. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $tenant = $this->context->getOrFail()->refresh();
        $stored = ServicesPageContent::stored($tenant);

        ServicesPageContent::save($tenant, (bool) $data['enabled'], $stored['cards']);
        $this->audit->record('website.services.switched', $tenant, ['enabled' => $stored['enabled']], ['enabled' => (bool) $data['enabled']], tenantId: $tenant->id);

        return ApiResponse::success(ServicesPageContent::forTenant($tenant->fresh()));
    }

    public function storeCard(Request $request): JsonResponse
    {
        return $this->saveCard($request, null);
    }

    /** Sent as a POST with `_method=PUT`: PHP only reads uploaded files from a POST. */
    public function updateCard(Request $request, string $id): JsonResponse
    {
        return $this->saveCard($request, $id);
    }

    public function destroyCard(string $id): JsonResponse
    {
        $tenant = $this->context->getOrFail()->refresh();
        $stored = ServicesPageContent::stored($tenant);
        $card = $this->find($stored['cards'], $id);

        $cards = array_values(array_filter($stored['cards'], fn (array $c) => (string) $c['id'] !== $id));
        ServicesPageContent::save($tenant, $stored['enabled'], $cards);
        if (! empty($card['image_path'])) {
            Storage::disk('public')->delete($card['image_path']);
        }
        $this->audit->record('website.services.card_deleted', $tenant, ['title' => $card['title_en'] ?? $card['title_km'] ?? ''], tenantId: $tenant->id);

        return ApiResponse::noContent();
    }

    private function saveCard(Request $request, ?string $id): JsonResponse
    {
        $data = $request->validate(ServicesPageContent::cardRules());
        $tenant = $this->context->getOrFail()->refresh();
        $stored = ServicesPageContent::stored($tenant);
        $old = $id === null ? null : $this->find($stored['cards'], $id);
        $others = array_values(array_filter($stored['cards'], fn (array $c) => $id === null || (string) $c['id'] !== $id));

        // One card opens the Computer School, one the Downloads page.
        if (in_array($data['kind'], ServicesPageContent::SINGLE_KINDS, true) && collect($others)->contains(fn (array $c) => ($c['kind'] ?? '') === $data['kind'])) {
            throw ValidationException::withMessages(['kind' => $data['kind'] === ServicesPageContent::KIND_SCHOOL
                ? __('Another card already opens the Computer School.')
                : __('Another card already opens the Downloads page.')]);
        }

        // An edit that does not send an order keeps the card where it was.
        $card = ['id' => $old['id'] ?? (string) Str::uuid(), 'kind' => $data['kind'], 'status' => $data['status'], 'sort_order' => (int) ($data['sort_order'] ?? $old['sort_order'] ?? count($others))];
        foreach ([...ServicesPageContent::CARD_TEXT, ...ServicesPageContent::PAGE_TEXT] as $field) {
            foreach (['km', 'en'] as $lang) {
                $card["{$field}_{$lang}"] = (string) ($data["{$field}_{$lang}"] ?? '');
            }
        }
        $card['button_url'] = (string) ($data['button_url'] ?? '');
        $card['link_url'] = $data['kind'] === ServicesPageContent::KIND_LINK ? (string) ($data['link_url'] ?? '') : '';

        // Its own page's address: as typed, else from the title; unique among the cards.
        if ($data['kind'] === ServicesPageContent::KIND_PAGE) {
            $taken = array_values(array_filter(array_map(fn (array $c) => (string) ($c['slug'] ?? ''), $others)));
            $wanted = (string) ($data['slug'] ?? '') ?: ($card['title_en'] ?: $card['title_km']);
            if (! empty($data['slug']) && in_array($data['slug'], $taken, true)) {
                throw ValidationException::withMessages(['slug' => __('Another card already uses this address.')]);
            }
            $card['slug'] = ServicesPageContent::uniqueSlug($wanted, $taken);
        } else {
            $card['slug'] = '';
        }

        $card['image_path'] = $old['image_path'] ?? null;
        $replaced = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store($tenant->storagePath('services'), 'public');
            if ($path === false) {
                abort(500, 'Failed to store the uploaded image.');
            }
            $replaced = $card['image_path'];
            $card['image_path'] = $path;
        }

        ServicesPageContent::save($tenant, $stored['enabled'], [...$others, $card]);
        if ($replaced !== null) {
            Storage::disk('public')->delete($replaced);
        }
        $this->audit->record($old === null ? 'website.services.card_created' : 'website.services.card_updated', $tenant, new: ['title' => $card['title_en'] ?: $card['title_km'], 'kind' => $card['kind'], 'slug' => $card['slug']], tenantId: $tenant->id);

        $content = ServicesPageContent::forTenant($tenant->fresh());
        $saved = collect($content['cards'])->firstWhere('id', $card['id']);

        return $old === null ? ApiResponse::created($saved) : ApiResponse::success($saved);
    }

    /** @param  list<array<string, mixed>>  $cards @return array<string, mixed> */
    private function find(array $cards, string $id): array
    {
        foreach ($cards as $card) {
            if ((string) ($card['id'] ?? '') === $id) {
                return $card;
            }
        }
        abort(404);
    }
}
