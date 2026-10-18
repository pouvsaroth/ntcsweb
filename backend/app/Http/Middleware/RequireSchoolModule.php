<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Billing\SchoolModules;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `school.module:website` — 404 unless the current school's plan includes the
 * module (docs/backoffice/stage-5-billing.md, 5c). For routes no permission
 * guards, such as the public website; everything behind a permission is
 * already filtered by PermissionRegistry.
 */
final class RequireSchoolModule
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SchoolModules $modules,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->has($this->context->id(), $module), 404);

        return $next($request);
    }
}
