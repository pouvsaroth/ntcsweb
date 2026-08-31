<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Credential verification, scoped to the school in context.
 *
 * The tenant boundary is enforced at lookup time: the candidate user is
 * selected from the resolved tenant only, so a correct email/phone and
 * password for School A are simply not recognised on School B's domain. That
 * is stronger than authenticating first and checking the tenant afterwards,
 * which would confirm the account's existence to the wrong school.
 */
final readonly class AuthService
{
    /**
     * A validly-formatted bcrypt hash of an arbitrary string. Checking against
     * this when no user matched keeps the response time for "unknown email"
     * indistinguishable from "wrong password", so the endpoint cannot be used
     * to enumerate accounts. What it is a hash *of* is irrelevant — verify cost
     * is dominated by the bcrypt cost factor, not the plaintext — it only has
     * to be shaped like a real hash or BcryptHasher rejects it outright.
     */
    private const TIMING_SAFE_DUMMY = '$2y$12$i/783SROl.gGh8CJrq5qsummjqT9rFkGjwkDC/NknuO5AMQZp5WUW';

    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  string  $login  an email address or a phone number
     *
     * @throws ValidationException on any failure, always with the same message
     */
    public function authenticate(string $login, string $password): User
    {
        $tenantId = $this->context->id();

        $email = mb_strtolower(trim($login));
        $phone = PhoneNumber::normalize($login);

        $user = User::query()
            ->with('roles')
            ->inTenant($tenantId)
            ->where(function ($query) use ($email, $phone) {
                $query->where('email', $email);

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();

        if ($user === null) {
            Hash::check($password, self::TIMING_SAFE_DUMMY);

            $this->audit->logFor(AuditAction::LOGIN_FAILED, 'Auth', $tenantId, new: [
                'login' => $login,
                'reason' => 'unknown_user',
            ]);

            $this->fail();
        }

        if (! Hash::check($password, $user->password)) {
            event(new Failed(config('tenancy.auth_guard'), $user, ['login' => $login]));

            $this->audit->logFor(AuditAction::LOGIN_FAILED, 'Auth', $tenantId, $user, ['reason' => 'bad_password']);

            $this->fail();
        }

        if (! $user->isActive()) {
            $this->audit->logFor(AuditAction::LOGIN_BLOCKED, 'Auth', $tenantId, $user, ['reason' => $user->status]);

            throw ValidationException::withMessages([
                'login' => $user->status === User::STATUS_SUSPENDED
                    ? __('This account has been suspended.')
                    : __('This account is not yet active. Please check your email for an invitation.'),
            ]);
        }

        // Rehash transparently if the configured cost has since increased.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->saveQuietly();
        }

        return $user;
    }

    /**
     * @return never
     *
     * @throws ValidationException
     */
    private function fail(): void
    {
        // One message for every failure mode. Distinguishing "no such user"
        // from "wrong password" hands an attacker a valid-account oracle.
        throw ValidationException::withMessages([
            'login' => __('auth.failed'),
        ]);
    }
}
