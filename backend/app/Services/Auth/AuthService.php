<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\Eloquent\Collection;
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
                'login' => match ($user->status) {
                    User::STATUS_SUSPENDED => __('This account has been suspended.'),
                    User::STATUS_PENDING_APPROVAL => __('Your registration is still awaiting the school\'s approval.'),
                    default => __('This account is not yet active. Please check your email for an invitation.'),
                },
            ]);
        }

        // Rehash transparently if the configured cost has since increased.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->saveQuietly();
        }

        return $user;
    }

    /**
     * The shared ERP login domain has no tenant in context at all (no
     * hostname, no explicit `tenant` field) — this is the entry point for
     * that one page's single email/phone + password form. Verifies the
     * password against every account across every (active) tenant that
     * matches the identity, rather than exactly one tenant-scoped row —
     * necessarily weaker than {@see authenticate()}'s "select from the
     * resolved tenant only" guarantee, since there is no tenant yet to
     * resolve against, but each candidate's own password hash still has to
     * verify, so this never turns into a plain "does this email exist"
     * oracle: failing every check looks identical to matching zero rows.
     *
     * @return Collection<int, User> every account whose password verified —
     *                               empty means "no such account anywhere"
     */
    public function authenticateAcrossTenants(string $login, string $password): Collection
    {
        $email = mb_strtolower(trim($login));
        $phone = PhoneNumber::normalize($login);

        $candidates = User::query()
            ->with(['roles', 'tenant'])
            ->active()
            ->where(function ($query) use ($email, $phone) {
                $query->where('email', $email);

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            // A suspended/closed school's accounts must not verify here even
            // if the password is right — same reasoning as active() above,
            // just reached through the tenant instead of the user. A NULL
            // tenant_id is a platform super admin, who has no tenant row to
            // check at all.
            ->where(function ($query) {
                $query->whereNull('tenant_id')->orWhereHas('tenant', fn ($query) => $query->active());
            })
            ->get();

        $verified = $candidates->filter(fn (User $user) => Hash::check($password, $user->password));

        if ($verified->isEmpty()) {
            // Same timing-safe shape as authenticate() below.
            Hash::check($password, self::TIMING_SAFE_DUMMY);
        }

        return $verified->values();
    }

    /**
     * A per-role concurrent-device cap (see User::maxConcurrentDevices()): a
     * user already signed in on that many devices must log one of them out
     * first, or have a School Admin clear it for them (see
     * UserController::forceLogout()) — there is no self-service override,
     * since by definition they can't reach the device that's still signed
     * in. There is deliberately no time-based expiry on the session side
     * either (see the migration that creates UserSession) — only an
     * explicit logout or an admin clears a slot, exactly matching "you must
     * log out first."
     *
     * A device is either an open browser session (one UserSession row) or a
     * live Sanctum token — the two transports share one pool of slots, so a
     * Teacher can't get three browser tabs *and* three mobile tokens.
     *
     * `$replacingDeviceName` excludes the one token case that isn't really
     * "another device": a token login re-using the same device name that
     * tokenResponse() is about to replace anyway (e.g. reinstalling the same
     * mobile app). Sessions have no equivalent — the session transport never
     * "replaces" a prior login, it just adds one more open browser.
     *
     * @throws ValidationException
     */
    public function ensureNoOtherActiveDevice(User $user, ?string $replacingDeviceName): void
    {
        $limit = $user->maxConcurrentDevices();

        if ($limit === null) {
            return;
        }

        $activeTokenCount = $user->tokens()
            ->when($replacingDeviceName !== null, fn ($query) => $query->where('name', '!=', $replacingDeviceName))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        $activeDeviceCount = $activeTokenCount + $user->loginSessions()->count();

        if ($activeDeviceCount >= $limit) {
            $this->audit->logFor(AuditAction::LOGIN_BLOCKED, 'Auth', $user->tenant_id, $user, ['reason' => 'already_logged_in_elsewhere']);

            throw ValidationException::withMessages([
                'login' => __('You are logging in another device, please logout first or you can ask admin for help.'),
            ]);
        }
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
