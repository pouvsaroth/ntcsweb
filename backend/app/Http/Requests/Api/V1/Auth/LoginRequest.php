<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Attempts allowed before the throttle bites, per login+IP+tenant.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accepts either an email address or a phone number — AuthService
            // tries both, so validation only needs to reject the obviously
            // empty/oversized case, not classify which one it is.
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],

            // Presence of a device name switches the response from a session
            // cookie to a Bearer token, for mobile apps and custom domains.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],

            'remember' => ['sometimes', 'boolean'],

            // Only honoured on a central domain; see RequestTenantResolver.
            'tenant' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @throws ValidationException when the caller is locked out
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ])->status(429);
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Keyed by tenant as well as the login identifier and IP: one school
     * being attacked must not lock the same person out at another school.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(implode('|', [
            'login',
            app(\App\Support\Tenancy\TenantContext::class)->id() ?? 'platform',
            Str::lower((string) $this->string('login')),
            $this->ip(),
        ]));
    }
}
