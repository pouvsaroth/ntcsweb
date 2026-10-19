<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\DatabaseManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back office → Website edits one school's public website: NewTech's own
 * (`platform.website_school`, docs/backoffice/stage-7-website.md). This
 * steps the request into that school — its tenant context, so every
 * website model is scoped to it, and its database connection — and out
 * again afterwards. Times stay in UTC, like the rest of the back office.
 */
final readonly class EnterWebsiteSchool
{
    public function __construct(
        private TenantContext $context,
        private DatabaseManager $databases,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) config('platform.website_school');
        $school = $slug === '' ? null : Tenant::query()->where('slug', $slug)->first();

        if ($school === null || ! $school->isActive()) {
            return ApiResponse::error(
                __('The website school ":slug" was not found or is not active.', ['slug' => $slug]),
                409,
                'WEBSITE_SCHOOL_MISSING',
            );
        }

        $this->context->set($school);
        // The test suite uses the static `tenant` connection, as ResolveTenant does.
        if (! app()->environment('testing')) {
            $this->databases->createTenantConnection($school);
        }

        try {
            return $next($request);
        } finally {
            if (! app()->environment('testing')) {
                $this->databases->purgeTenantConnection();
            }
        }
    }
}
