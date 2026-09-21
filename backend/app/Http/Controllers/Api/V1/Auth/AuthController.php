<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\TransientToken;

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
        private readonly CurrencyConversionService $currencyConversion,
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

        $deviceName = $request->string('device_name')->trim()->toString();

        $this->auth->ensureNoOtherActiveDevice($user, $deviceName !== '' ? $deviceName : null);

        $user->recordLogin($request->ip());

        $this->audit->logFor(AuditAction::LOGIN, 'Auth', $user->tenant_id, $user, [
            'transport' => $deviceName !== '' ? 'token' : 'session',
        ]);

        return $deviceName !== ''
            ? $this->tokenResponse($user, $deviceName)
            : $this->sessionResponse($request, $user);
    }

    /**
     * The shared ERP login domain (see RequestTenantResolver/TenantHost's
     * `isCentral()`) has no hostname to resolve a tenant from, so the login
     * form can't know in advance which school's credentials to check —
     * unlike a school's own subdomain, where that's implicit. This lets it
     * ask first: given an email/phone, which school(s) does an account
     * actually exist at, so the picker (or an unambiguous single choice) can
     * fill in the `tenant` field before the real `login()` call above runs
     * completely unchanged.
     *
     * Deliberately public and deliberately minimal (id/slug/name only, same
     * shape as TenantDirectoryController) — this is a *slightly* stronger
     * oracle than that endpoint (it confirms an email has an account
     * *somewhere*, not just that a school exists), which is exactly why it's
     * throttled the same as login itself (`throttle:auth`, see routes/api.php)
     * rather than the looser `throttle:api` the plain tenant directory uses.
     * An unknown identity or one with no active account anywhere returns an
     * empty list, not an error — the frontend falls back to manual entry.
     */
    public function tenantsForLogin(Request $request): JsonResponse
    {
        $identity = trim((string) $request->query('identity', ''));

        if ($identity === '') {
            return ApiResponse::success([]);
        }

        $email = mb_strtolower($identity);
        $phone = PhoneNumber::normalize($identity);

        $tenants = Tenant::query()
            ->active()
            ->whereHas('users', function ($query) use ($email, $phone) {
                $query->active()->where(function ($query) use ($email, $phone) {
                    $query->where('email', $email);

                    if ($phone !== null) {
                        $query->orWhere('phone', $phone);
                    }
                });
            })
            ->orderBy('name')
            ->get(['id', 'slug', 'name']);

        return ApiResponse::success(
            $tenants->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ])->all()
        );
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

        if ($token !== null && ! $token instanceof TransientToken) {
            $token->delete();
        } else {
            $user?->deactivateSessionLogin();

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

        $tenant = $this->context->get();

        return ApiResponse::success(
            new UserResource($user),
            meta: [
                'permissions' => $user->isSuperAdmin() ? ['*'] : $user->permissionSlugs(),
                'is_super_admin' => $user->isSuperAdmin(),
                'tenant' => $tenant !== null
                    ? [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'default_currency' => $tenant->default_currency,
                        // Today's rate, not tied to any one transaction date
                        // — this is "what should the enrollment form show
                        // right now", see EnrollmentPackageForm.vue. Each
                        // enrollment still re-resolves its own rate
                        // server-side for the date it's actually billed on.
                        'khr_per_usd_rate' => $this->currencyConversion->rateForDate(now()),
                    ]
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

        // A student's own "official" photo (used on ID cards, exam
        // applications, and the admin Students list — see
        // Student::photoUrl()) is a completely separate field from their
        // login avatar. Keeping them in sync here means a student updating
        // their profile picture from Account Settings doesn't leave the
        // school admin looking at a blank photo in the Students list.
        if ($newAvatarPath !== null && $user->student !== null) {
            $previousStudentPhotoPath = $user->student->photo_path;
            $user->student->update(['photo_path' => $newAvatarPath]);

            if ($previousStudentPhotoPath !== null && $previousStudentPhotoPath !== $previousAvatarPath) {
                Storage::disk('public')->delete($previousStudentPhotoPath);
            }
        }

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
        $user->activateSessionLogin();

        // Rotate the session id on privilege change to defeat session fixation.
        $request->session()->regenerate();

        return ApiResponse::success([
            'user' => new UserResource($user->loadMissing('roles', 'tenant')),
        ], __('Signed in.'));
    }
}
