<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\HomeSlideResource;
use App\Http\Responses\ApiResponse;
use App\Models\HomeSlide;
use Illuminate\Http\JsonResponse;

/**
 * The public homepage's image slider. Unauthenticated — gated only by
 * `tenant.required` on the route group, same as every other public endpoint.
 */
final class HomeSlideController extends Controller
{
    public function index(): JsonResponse
    {
        $slides = HomeSlide::query()->active()->ordered()->get();

        return ApiResponse::success(HomeSlideResource::collection($slides));
    }
}
