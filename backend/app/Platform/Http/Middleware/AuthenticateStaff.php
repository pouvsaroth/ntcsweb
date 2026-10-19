<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SecuritySettings;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in staff account, still active, with its second factor still on.
 * Anything else is 401 — including every school session and every Sanctum
 * token, which the `staff` guard never reads.
 *
 * An account disabled, or its 2FA reset, while signed in is signed out here
 * on its next request rather than left running until the session expires.
 *
 * Implements AuthenticatesRequests so Laravel's middleware priority runs it
 * BEFORE SubstituteBindings: otherwise `staff/{staff}` looks the record up
 * first, and a signed-out caller learns which ids exist from 404 vs 401.
 */
final class AuthenticateStaff implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('staff');
        $staff = $guard->user();

        // The second factor is only demanded while Settings → Security requires
        // it: switched back on, a session without one ends and the next
        // sign-in sets an authenticator up.
        if (! $staff instanceof PlatformStaff || ! $staff->isActive() || (! $staff->hasTwoFactor() && SecuritySettings::twoFactorRequired())) {
            if ($staff !== null) {
                $guard->logout();
            }

            throw new AuthenticationException(guards: ['staff']);
        }

        Auth::shouldUse('staff');

        return $next($request);
    }
}
