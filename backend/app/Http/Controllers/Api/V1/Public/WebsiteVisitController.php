<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Analytics\WebsiteVisitStats;
use Illuminate\Http\JsonResponse;

/**
 * The public site's small "visitors" footer line. Unauthenticated — gated
 * only by `tenant.required` on the route group, same as every other public
 * endpoint.
 */
final class WebsiteVisitController extends Controller
{
    /**
     * Called once per browser per calendar day (see site.ts's `pingVisit()`)
     * — records today's visit, then returns the same summary `stats()` does
     * so the frontend doesn't need a second round trip.
     */
    public function record(): JsonResponse
    {
        WebsiteVisitStats::record();

        return ApiResponse::success(WebsiteVisitStats::summary());
    }

    /**
     * Read-only — used on every visit after the first one today, so the
     * footer's numbers stay current without incrementing the count again.
     */
    public function stats(): JsonResponse
    {
        return ApiResponse::success(WebsiteVisitStats::summary());
    }
}
