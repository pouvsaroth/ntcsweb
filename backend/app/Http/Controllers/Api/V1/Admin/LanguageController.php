<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLanguageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLanguageRequest;
use App\Http\Resources\LanguageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Language;
use App\Services\BaseData\LookupCache;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-global (no tenant scoping) — see the model's own docblock. A
 * language change affects every tenant's cached lookups at once, so writes
 * here invalidate every tenant's cache, not just the acting admin's own.
 */
final class LanguageController extends Controller
{
    public function __construct(private readonly LookupCache $cache) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Language::class);

        $languages = ApiQuery::for(Language::query(), $request)
            ->searchable('code', 'name', 'native_name')
            ->filterable(['is_active'])
            ->sortable(['sort_order', 'code', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(LanguageResource::collection($languages));
    }

    public function store(StoreLanguageRequest $request): JsonResponse
    {
        $language = DB::transaction(function () use ($request) {
            $data = $request->validated();

            if ($data['is_default'] ?? false) {
                Language::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Language::query()->create($data);
        });

        $this->invalidateEveryTenant();

        return ApiResponse::created(new LanguageResource($language));
    }

    public function show(Language $language): JsonResponse
    {
        $this->authorize('view', $language);

        return ApiResponse::success(new LanguageResource($language));
    }

    public function update(UpdateLanguageRequest $request, Language $language): JsonResponse
    {
        $data = $request->validated();

        if (($data['is_active'] ?? true) === false && $language->is_default) {
            return ApiResponse::error('The default language cannot be deactivated. Set a different language as default first.', 422);
        }

        DB::transaction(function () use ($language, $data) {
            if ($data['is_default'] ?? false) {
                Language::query()->where('is_default', true)->whereKeyNot($language->getKey())->update(['is_default' => false]);
            }

            $language->update($data);
        });

        $this->invalidateEveryTenant();

        return ApiResponse::success(new LanguageResource($language->fresh()));
    }

    public function destroy(Language $language): JsonResponse
    {
        $this->authorize('delete', $language);

        if ($language->is_default) {
            return ApiResponse::error('The default language cannot be deleted. Set a different language as default first.', 422);
        }

        if ($language->lookupValueTranslations()->exists()) {
            return ApiResponse::error('This language has translations recorded and cannot be deleted. Deactivate it instead.', 422);
        }

        $language->delete();
        $this->invalidateEveryTenant();

        return ApiResponse::noContent();
    }

    /** Every tenant's cached lookups may include this language's translations, so every tenant's cache must clear. */
    private function invalidateEveryTenant(): void
    {
        \App\Models\Tenant::query()->pluck('id')->each(fn ($id) => $this->cache->invalidateTenant((int) $id));
    }
}
