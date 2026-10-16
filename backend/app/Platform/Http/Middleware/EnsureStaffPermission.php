<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Platform\Models\PlatformStaff;
use App\Platform\Support\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `platform.can:<key>` — on every platform route past sign-in. Runs after
 * AuthenticateStaff.
 *
 *   password must be changed first         409 PASSWORD_CHANGE_REQUIRED
 *   the route names no platform.* key      404
 *   the staff member does not hold it      404, and the attempt is audited
 *
 * 404 rather than 403: a refusal should not confirm the route is there.
 */
final class EnsureStaffPermission
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('staff');

        if ($staff->must_change_password) {
            return ApiResponse::error(__('Change your password to continue.'), 409, 'PASSWORD_CHANGE_REQUIRED');
        }

        if ($permission === null || ! str_starts_with($permission, 'platform.')) {
            throw new NotFoundHttpException;
        }

        if (! $staff->hasPermission($permission)) {
            $this->audit->record('permission.denied', new: ['permission' => $permission, 'route' => $request->path()]);

            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
