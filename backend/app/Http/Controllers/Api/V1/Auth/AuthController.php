<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-in and sign-out for both supported transports.
 *
 *   Session   first-party SPA on a stateful domain. Login sets an HttpOnly
 *             cookie; nothing sensitive is ever handed to JavaScript.
 *   Token     mobile apps, custom domains and third parties. Send a
 *             `device_name` and get a Sanctum Bearer token back.
 *
 * Which one you get is decided by the request, not by configuration, and the
 * `auth:sanctum` guard accepts either without the rest of the API caring.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        try {
            $user = $this->auth->authenticate(
                $request->string('login')->toString(),
                $request->string('password')->toString(),
            );
        } catch (\Throwable $e) {
            $request->hitRateLimiter();

            throw $e;
        }

        $request->clearRateLimiter();

        $user->recordLogin($request->ip());

        $this->audit->logFor('auth.login', $user->tenant_id, $user, [
            'transport' => $request->filled('device_name') ? 'token' : 'session',
        ]);

        $deviceName = $request->string('device_name')->trim()->toString();

        return $deviceName !== ''
            ? $this->tokenResponse($user, $deviceName)
            : $this->sessionResponse($request, $user);
    }

    /**
     * Ends the current session or revokes the presenting token — never both,
     * and never every token, so signing out of a phone does not sign the user
     * out of their laptop.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->audit->logFor('auth.logout', $user?->tenant_id, $user);

        $token = $user?->currentAccessToken();

        if ($token !== null && ! $token instanceof \Laravel\Sanctum\TransientToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(message: __('Signed out.'));
    }

    /**
     * Everything the SPA needs to render its shell in one call: the user, their
     * school, and the flattened permission list the UI hides controls by.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadMissing('roles', 'tenant');

        return ApiResponse::success(
            new UserResource($user),
            meta: [
                'permissions' => $user->isSuperAdmin() ? ['*'] : $user->permissionSlugs(),
                'is_super_admin' => $user->isSuperAdmin(),
                'tenant' => $this->context->has()
                    ? ['id' => $this->context->id(), 'name' => $this->context->get()?->name]
                    : null,
            ],
        );
    }

    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        // Same-named tokens are replaced so re-installing an app does not leave
        // an orphaned credential behind that nobody will ever revoke.
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, ['*'], now()->addDays(
            (int) config('sanctum.expiration_days', 30)
        ));

        return ApiResponse::success([
            'user' => new UserResource($user->loadMissing('roles', 'tenant')),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at,
        ], __('Signed in.'));
    }

    private function sessionResponse(LoginRequest $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user, $request->boolean('remember'));

        // Rotate the session id on privilege change to defeat session fixation.
        $request->session()->regenerate();

        return ApiResponse::success([
            'user' => new UserResource($user->loadMissing('roles', 'tenant')),
        ], __('Signed in.'));
    }
}
