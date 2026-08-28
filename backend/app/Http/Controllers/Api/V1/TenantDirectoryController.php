<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated, platform-wide, deliberately minimal: the school picker on
 * the login/forgot-password screens needs a name and a slug to submit, and
 * nothing else. This is what stands in for hostname-based tenant resolution
 * on a central domain (localhost, or any address with no subdomain of its
 * own) — see docs/multi-tenancy.md.
 *
 * Listing school *names* publicly is not a new disclosure: every school also
 * has its own public website reachable at its subdomain, so its existence
 * and name are already public by design. Contact details, settings, and
 * anything else on Tenant stay out of this response.
 */
final class TenantDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = ApiQuery::for(Tenant::query()->active(), $request)
            ->searchable('name', 'slug')
            ->sortable(['name'], default: 'name')
            ->maxPerPage(100)
            ->paginate();

        return ApiResponse::success(
            $tenants->through(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ]),
        );
    }
}
