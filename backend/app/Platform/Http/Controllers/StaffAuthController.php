<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\Resources\StaffResource;
use App\Platform\Models\PlatformStaff;
use App\Platform\Services\StaffSignIn;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\Totp;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Staff sign-in: password, then a code. See StaffSignIn for the steps and
 * docs/backoffice/stage-1-foundation.md for the routes.
 */
final class StaffAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly StaffSignIn $signIn,
        private readonly PlatformAudit $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $key = 'platform-login|'.Str::lower($data['email']).'|'.$request->ip();
        $this->throttle($key, 'email');

        $staff = $this->signIn->checkPassword($request, $data['email'], $data['password']);
        if ($staff === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        return ApiResponse::success(['two_factor' => $staff->hasTwoFactor() ? 'required' : 'setup']);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $staff = $this->pendingOrFail($request);

        $key = 'platform-2fa|'.$staff->id.'|'.$request->ip();
        $this->throttle($key, 'code');

        if (! $this->signIn->verifySecondFactor($staff, $data['code'])) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['code' => __('That code is not right.')]);
        }

        RateLimiter::clear($key);
        $this->signIn->complete($request, $staff);

        return $this->session($request);
    }

    /**
     * First sign-in only. An account that already has 2FA is refused — or a
     * password alone would be enough to replace the second factor.
     */
    public function setupTwoFactor(Request $request): JsonResponse
    {
        $staff = $this->pendingOrFail($request);
        if ($staff->hasTwoFactor()) {
            return ApiResponse::error(__('Two-factor sign-in is already set up.'), 409, 'TWO_FACTOR_ALREADY_SET_UP');
        }

        $secret = $this->signIn->beginEnrolment($staff);

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, $staff->email, (string) config('platform.two_factor_issuer')),
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $staff = $this->pendingOrFail($request);
        if ($staff->hasTwoFactor()) {
            return ApiResponse::error(__('Two-factor sign-in is already set up.'), 409, 'TWO_FACTOR_ALREADY_SET_UP');
        }

        $key = 'platform-2fa|'.$staff->id.'|'.$request->ip();
        $this->throttle($key, 'code');

        $codes = $this->signIn->confirmEnrolment($staff, $data['code']);
        if ($codes === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['code' => __('That code is not right.')]);
        }

        RateLimiter::clear($key);
        $this->audit->record('auth.two_factor_enabled', $staff, actor: $staff);
        $this->signIn->complete($request, $staff);

        return ApiResponse::success([
            ...$this->sessionPayload($staff->fresh()),
            'recovery_codes' => $codes,
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        return ApiResponse::success($this->sessionPayload($request->user('staff')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.signed_out');
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::noContent();
    }

    public function password(Request $request): JsonResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('staff');

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:staff'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        DB::connection($staff->getConnectionName())->transaction(function () use ($staff, $data) {
            $staff->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
            $this->audit->record('auth.password_changed', $staff);
        });

        return ApiResponse::success($this->sessionPayload($staff->fresh()));
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('staff');
        $request->validate(['current_password' => ['required', 'string', 'current_password:staff']]);

        $codes = DB::connection($staff->getConnectionName())->transaction(function () use ($staff) {
            $codes = $this->signIn->regenerateRecoveryCodes($staff);
            $this->audit->record('auth.recovery_codes_regenerated', $staff);

            return $codes;
        });

        return ApiResponse::success(['recovery_codes' => $codes]);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(PlatformStaff $staff): array
    {
        $staff->loadMissing('roles');

        return [
            'staff' => new StaffResource($staff),
            'permissions' => $staff->permissions(),
            'must_change_password' => $staff->must_change_password,
            'recovery_codes_left' => count($staff->two_factor_recovery_codes ?? []),
        ];
    }

    private function pendingOrFail(Request $request): PlatformStaff
    {
        return $this->signIn->pending($request)
            ?? throw new HttpResponseException(ApiResponse::error(
                __('Your sign-in expired. Enter your password again.'), 401, 'SIGN_IN_EXPIRED',
            ));
    }

    private function throttle(string $key, string $field): void
    {
        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw new HttpResponseException(ApiResponse::error(
            trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            429,
            'RATE_LIMITED',
            [$field => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])]],
        ));
    }
}
