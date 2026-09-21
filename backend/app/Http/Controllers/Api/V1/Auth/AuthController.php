<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\SelectLoginTenantRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
    /** Prefix for the short-lived cache entry a "pick your school" response is redeemed against — see respondWithTenantChoices()/selectTenant(). */
    private const TENANT_SELECTION_CACHE_PREFIX = 'login-tenant-selection:';

    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContext $context,
        private readonly CurrencyConversionService $currencyConversion,
        private readonly AuditLogger $audit,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        // The shared ERP domain has no tenant in context at all — no
        // hostname, no explicit `tenant` field/header — since ResolveTenant
        // already ran before this controller and found nothing. Every other
        // domain (a school's own, or a central one with an explicit `tenant`
        // typed/picked) already has a tenant resolved by now, so this only
        // ever branches for the ERP domain's single email/phone+password
        // form — see AuthService::authenticateAcrossTenants().
        if ($this->context->id() === null && ! $this->context->isPlatform()) {
            return $this->loginAcrossTenants($request);
        }

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

        return $this->finishLogin($request, $user);
    }

    private function loginAcrossTenants(LoginRequest $request): JsonResponse
    {
        $verified = $this->auth->authenticateAcrossTenants(
            $request->string('login')->toString(),
            $request->string('password')->toString(),
        );

        if ($verified->isEmpty()) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        $request->clearRateLimiter();

        if ($verified->count() > 1) {
            return $this->respondWithTenantChoices($verified);
        }

        /** @var User $user */
        $user = $verified->first();

        if ($user->tenant_id !== null) {
            $this->context->set($user->tenant);
        }

        return $this->finishLogin($request, $user);
    }

    /**
     * More than one account verified for this identity+password — cache the
     * exact candidate ids (never trust a tenant id handed back by the client
     * alone) behind a short-lived, single-use token, and hand back just
     * enough for the picker: which schools, not which accounts. No session
     * or token is issued yet; see selectTenant().
     */
    private function respondWithTenantChoices(Collection $verified): JsonResponse
    {
        $token = Str::random(40);

        Cache::put(
            self::TENANT_SELECTION_CACHE_PREFIX.$token,
            $verified->pluck('id')->all(),
            now()->addMinutes(5),
        );

        return ApiResponse::success([
            'requires_tenant_selection' => true,
            'selection_token' => $token,
            'tenants' => $verified->map(fn (User $user) => [
                'id' => $user->tenant_id,
                'slug' => $user->tenant?->slug,
                'name' => $user->tenant?->name,
            ])->values(),
        ]);
    }

    /**
     * The second step of the ERP domain's login, only reached after
     * loginAcrossTenants() found more than one verified account — the
     * password already checked out, so this only needs to confirm the
     * chosen school was actually one of the accounts that verified (via the
     * cache token, not by trusting the request's own tenant_id) before
     * finishing the same way any other login does.
     */
    public function selectTenant(SelectLoginTenantRequest $request): JsonResponse
    {
        $cacheKey = self::TENANT_SELECTION_CACHE_PREFIX.$request->validated('selection_token');
        $candidateIds = Cache::pull($cacheKey);

        if (! is_array($candidateIds)) {
            throw ValidationException::withMessages(['selection_token' => __('auth.failed')]);
        }

        $user = User::query()
            ->with(['roles', 'tenant'])
            ->whereIn('id', $candidateIds)
            ->where('tenant_id', $request->validated('tenant_id'))
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages(['tenant_id' => __('auth.failed')]);
        }

        $this->context->set($user->tenant);

        return $this->finishLogin($request, $user);
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
            $user?->forgetLoginSession($request->session()->getId());

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

        // hostname() only returns the real custom domain when primaryDomain
        // is already eager-loaded — otherwise it silently falls back to
        // slug.root_domain, and the context's tenant never carries this
        // relation on its own.
        $tenant = $this->context->get()?->loadMissing('primaryDomain');

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
                        // Now that the admin app is served from a different
                        // origin than the school's own public website (see
                        // docs/multi-tenancy.md's ERP domain section),
                        // AdminHeader's "Go to website" link needs this to
                        // build an absolute URL rather than a same-origin `/`.
                        'hostname' => $tenant->hostname(),
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

    /**
     * The tail end of every successful login, regardless of which of the
     * three paths above got here (single-tenant, ERP single-match, or ERP
     * after a tenant pick) — the per-role concurrent-device limit, recording
     * the login, auditing it, and issuing whichever of the two response
     * shapes applies.
     */
    private function finishLogin(Request $request, User $user): JsonResponse
    {
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

    private function sessionResponse(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user, $request->boolean('remember'));

        // Rotate the session id on privilege change to defeat session
        // fixation — done before recordLoginSession() so the row is keyed by
        // the id this session will actually keep using.
        $request->session()->regenerate();

        $user->recordLoginSession($request->session()->getId(), $request->ip(), $request->userAgent());

        return ApiResponse::success([
            'user' => new UserResource($user->loadMissing('roles', 'tenant')),
        ], __('Signed in.'));
    }
}
