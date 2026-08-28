<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declares that a route is meaningless without a school — every tenant-scoped
 * endpoint, and the whole public school website.
 *
 * Answers 404 rather than 400: on an unknown hostname there is genuinely no
 * such site, and it avoids confirming which hostnames are live.
 */
final readonly class RequireTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->has()) {
            return $next($request);
        }

        // Platform mode is a super admin acting across all schools; routes that
        // need one specific school still have to say which.
        abort(Response::HTTP_NOT_FOUND, $this->context->isPlatform()
            ? 'This endpoint targets a single tenant. Select one with the X-Tenant header.'
            : 'No school could be resolved for this request.');
    }
}
