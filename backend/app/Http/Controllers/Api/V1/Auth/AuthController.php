<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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

        $this->audit->logFor(AuditAction::LOGIN, 'Auth', $user->tenant_id, $user, [
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

        $this->audit->logFor(AuditAction::LOGOUT, 'Auth', $user?->tenant_id, $user);

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

    /**
     * The acting user's own name/phone/picture — never a route parameter,
     * always `$request->user()`. Deliberately separate from the tenant
     * admin's Staff/Student edit forms: this is "edit myself", reachable by
     * every authenticated user regardless of their role or permissions.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $previousAvatarPath = $user->avatar_path;
        $newAvatarPath = $request->hasFile('avatar') ? $this->storeAvatar($request, $user) : null;

        $user->update([
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            ...($newAvatarPath !== null ? ['avatar_path' => $newAvatarPath] : []),
        ]);

        // Only removed once the new path is safely persisted — see
        // HomeSlideController::update() for why this ordering matters.
        if ($newAvatarPath !== null && $previousAvatarPath !== null) {
            Storage::disk('public')->delete($previousAvatarPath);
        }

        return ApiResponse::success(new UserResource($user->fresh()->loadMissing('roles', 'tenant')));
    }

    private function storeAvatar(UpdateProfileRequest $request, User $user): string
    {
        // Not TenantContext::getOrFail(): a platform super admin (tenant_id
        // NULL) has no tenant to fail on, and this is "where does this
        // user's own file live", not a tenant-scoped write.
        $prefix = $user->tenant_id !== null ? "tenants/{$user->tenant_id}/avatars" : 'platform/avatars';

        $path = $request->file('avatar')->store($prefix, 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded avatar.');
        }

        return $path;
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
