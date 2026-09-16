<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;

/**
 * The public site's Promotion page. Unauthenticated — gated only by
 * `tenant.required` on the route group, same as every other public endpoint.
 */
final class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $promotions = Promotion::query()->active()->ordered()->get();

        return ApiResponse::success(PromotionResource::collection($promotions));
    }
}
