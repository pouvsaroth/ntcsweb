<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveTranslationOverridesRequest;
use App\Http\Responses\ApiResponse;
use App\Models\TranslationOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The school's own wording of the app's text (Settings > Language >
 * Translation). Reading is open to anyone signed in to this school — the
 * admin app loads it once after sign-in so everyone sees the school's words
 * — while saving needs base-data.manage-translations (see
 * SaveTranslationOverridesRequest).
 */
final class TranslationOverrideController extends Controller
{
    /**
     * Grouped by locale, keys flat: { km: { "adminNav.items.students": "..." } }.
     */
    public function index(): JsonResponse
    {
        $overrides = array_fill_keys(TranslationOverride::LOCALES, []);

        TranslationOverride::query()->orderBy('key')->get(['locale', 'key', 'value'])
            ->each(function (TranslationOverride $row) use (&$overrides) {
                $overrides[$row->locale][$row->key] = $row->value;
            });

        // Objects, never `[]`, so an empty locale still decodes as a map.
        return ApiResponse::success(array_map(fn (array $words) => (object) $words, $overrides));
    }

    /**
     * Applies a batch of edits: a value sets (or replaces) the school's word,
     * an empty one removes it so the built-in text shows again.
     */
    public function update(SaveTranslationOverridesRequest $request): JsonResponse
    {
        DB::connection('tenant')->transaction(function () use ($request) {
            foreach ($request->validated('changes') as $change) {
                $value = trim((string) ($change['value'] ?? ''));
                $existing = TranslationOverride::query()->where('locale', $change['locale'])->where('key', $change['key'])->first();

                if ($value === '') {
                    $existing?->delete();
                } elseif ($existing === null) {
                    TranslationOverride::query()->create(['locale' => $change['locale'], 'key' => $change['key'], 'value' => $value]);
                } elseif ($existing->value !== $value) {
                    $existing->update(['value' => $value]);
                }
            }
        });

        return $this->index();
    }
}
