<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspending an account must take effect immediately, not at next login. Every
 * authenticated request re-checks the account's status and the school's, so a
 * live token stops working the moment either is disabled.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isActive()) {
            return $this->deny($user->status === User::STATUS_SUSPENDED
                ? 'This account has been suspended.'
                : 'This account is not active.');
        }

        // A school that is suspended or archived takes its users with it.
        if ($user->tenant_id !== null && ! $user->tenant?->isActive()) {
            return $this->deny('This school is currently unavailable. Please contact the platform administrator.');
        }

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => ['code' => 'ACCOUNT_INACTIVE'],
        ], Response::HTTP_FORBIDDEN);
    }
}
