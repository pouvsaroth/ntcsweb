<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GeographyResource;
use App\Http\Responses\ApiResponse;
use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cambodia's administrative hierarchy, for cascading address selects (the
 * student registration form). Platform-wide reference data — no tenant
 * scoping, no auth beyond being a signed-in admin (same authenticated group
 * every other admin route sits in). See the `create_cambodia_geography_tables`
 * migration for provenance.
 */
final class GeographyController extends Controller
{
    public function provinces(): JsonResponse
    {
        return ApiResponse::success(
            GeographyResource::collection(Province::query()->orderBy('name_latin')->get())
        );
    }

    public function districts(Request $request): JsonResponse
    {
        $request->validate(['province_id' => ['required', 'integer', 'exists:provinces,id']]);

        $districts = District::query()
            ->where('province_id', $request->integer('province_id'))
            ->orderBy('name_latin')
            ->get();

        return ApiResponse::success(GeographyResource::collection($districts));
    }

    public function communes(Request $request): JsonResponse
    {
        $request->validate(['district_id' => ['required', 'integer', 'exists:districts,id']]);

        $communes = Commune::query()
            ->where('district_id', $request->integer('district_id'))
            ->orderBy('name_latin')
            ->get();

        return ApiResponse::success(GeographyResource::collection($communes));
    }

    public function villages(Request $request): JsonResponse
    {
        $request->validate(['commune_id' => ['required', 'integer', 'exists:communes,id']]);

        $villages = Village::query()
            ->where('commune_id', $request->integer('commune_id'))
            ->orderBy('name_latin')
            ->get();

        return ApiResponse::success(GeographyResource::collection($villages));
    }

    /**
     * Resolves a village's full ancestry from its code alone — what the
     * student edit form uses to pre-select all four cascading dropdowns for
     * an existing `village_code` without loading all ~14,000 villages
     * client-side to find the right one.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['village_code' => ['required', 'string', 'exists:villages,code']]);

        $village = Village::query()
            ->with('commune.district.province')
            ->where('code', $request->string('village_code'))
            ->firstOrFail();

        return ApiResponse::success([
            'province' => new GeographyResource($village->commune->district->province),
            'district' => new GeographyResource($village->commune->district),
            'commune' => new GeographyResource($village->commune),
            'village' => new GeographyResource($village),
        ]);
    }
}
