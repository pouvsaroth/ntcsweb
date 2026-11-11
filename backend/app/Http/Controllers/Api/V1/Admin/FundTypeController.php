<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Services\BaseData\LookupValueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fund > the "+" beside Fund type: add a fund type without going to Base
 * Data. It is saved as a FUND_TYPE Base Data value (English and Khmer
 * names), with a code made from the English name. Anyone who can add funds.
 */
final class FundTypeController extends Controller
{
    public const CATEGORY = 'FUND_TYPE';

    public function store(Request $request, LookupValueService $values): JsonResponse
    {
        $this->authorize('create', Fund::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_km' => ['nullable', 'string', 'max:255'],
        ]);
        $name = trim($data['name']);

        $category = LookupCategory::query()->where('code', self::CATEGORY)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['name' => __('The Fund type list is missing in Base Data.')]);
        }

        // The same name (any language) already there: say so rather than add it twice.
        $exists = LookupValue::query()
            ->where('lookup_category_id', $category->id)
            ->whereHas('translations', fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => __('This fund type is already in the list.')]);
        }

        $value = $values->create([
            'lookup_category_id' => $category->id,
            'code' => $this->codeFor($category->id, $name),
            'is_active' => true,
            'sort_order' => (int) LookupValue::query()->where('lookup_category_id', $category->id)->max('sort_order') + 1,
            'translations' => [
                'en' => ['name' => $name],
                'km' => ['name' => trim((string) ($data['name_km'] ?? '')) ?: $name],
            ],
        ], $request->user());

        return ApiResponse::created(['id' => $value->id, 'code' => $value->code, 'name' => $name]);
    }

    /** "Staff party" -> STAFF_PARTY (STAFF_PARTY_2 when taken); TYPE_n for a name with no Latin letters. */
    private function codeFor(int $categoryId, string $name): string
    {
        $base = Str::of(Str::ascii($name))->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(40, '')->toString();
        $base = $base !== '' ? $base : 'TYPE';
        $taken = LookupValue::query()->where('lookup_category_id', $categoryId)->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        $code = $base;
        for ($i = 2; in_array($code, $taken, true); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }
}
