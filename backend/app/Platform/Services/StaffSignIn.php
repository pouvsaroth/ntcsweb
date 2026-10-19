<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Models\PlatformStaff;
use App\Platform\Support\PlatformAudit;
use App\Platform\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The two steps of a staff sign-in, and enrolling the second factor.
 *
 * A correct password signs nobody in. It parks the account's id in the
 * session for a few minutes ("pending"), and only a correct code — or a
 * first-time enrolment confirmed with one — turns that into a staff guard
 * login. So a stolen password alone never reaches a single platform route —
 * unless an Owner switched the code off in Settings → Security
 * (SecuritySettings), when StaffAuthController completes it straight away.
 */
final class StaffSignIn
{
    private const PENDING_KEY = 'platform.sign_in.pending';

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly PlatformAudit $audit) {}

    /** Step one. Null for a wrong password, an unknown email or a disabled account — alike on purpose. */
    public function checkPassword(Request $request, string $email, string $password): ?PlatformStaff
    {
        $staff = PlatformStaff::query()->where('email', Str::lower(trim($email)))->first();

        // Hash something either way, so an unknown email takes as long as a known one.
        $ok = $staff ? Hash::check($password, $staff->password) : (Hash::make($password) && false);

        if (! $staff || ! $ok || ! $staff->isActive()) {
            return null;
        }

        $request->session()->put(self::PENDING_KEY, [
            'id' => $staff->getKey(),
            'expires' => now()->addMinutes((int) config('platform.pending_login_minutes'))->getTimestamp(),
        ]);

        return $staff;
    }

    /** The account whose password was just accepted, while that is still fresh. */
    public function pending(Request $request): ?PlatformStaff
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $staff = PlatformStaff::query()->find($pending['id']);

        return $staff?->isActive() ? $staff : null;
    }

    /** Step two: a code from the app, or one unused recovery code. */
    public function verifySecondFactor(PlatformStaff $staff, string $code): bool
    {
        if (! $staff->hasTwoFactor()) {
            return false;
        }

        $step = Totp::verify($staff->two_factor_secret, $code, now()->getTimestamp(), $staff->two_factor_last_step);
        if ($step !== null) {
            $staff->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($staff, $code);
    }

    /** Start (or restart) enrolment: a fresh secret, not yet confirmed. */
    public function beginEnrolment(PlatformStaff $staff): string
    {
        $secret = Totp::generateSecret();
        $staff->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /**
     * Finish enrolment with one code from the app. Returns the recovery codes
     * — the only time they are ever shown — or null for a wrong code.
     *
     * @return list<string>|null
     */
    public function confirmEnrolment(PlatformStaff $staff, string $code): ?array
    {
        if ($staff->two_factor_secret === null) {
            return null;
        }

        $step = Totp::verify($staff->two_factor_secret, $code, now()->getTimestamp());
        if ($step === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $staff->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map($this->hashCode(...), $codes),
        ])->save();

        return $codes;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(PlatformStaff $staff): array
    {
        $codes = $this->newRecoveryCodes();
        $staff->forceFill(['two_factor_recovery_codes' => array_map($this->hashCode(...), $codes)])->save();

        return $codes;
    }

    /** @param  bool  $passwordOnly  Settings → Security asked for no code; recorded with the sign-in. */
    public function complete(Request $request, PlatformStaff $staff, bool $passwordOnly = false): void
    {
        $request->session()->forget(self::PENDING_KEY);

        DB::connection($staff->getConnectionName())->transaction(function () use ($request, $staff, $passwordOnly) {
            $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
            $this->audit->record('auth.signed_in', $staff, new: $passwordOnly ? ['two_factor' => 'not required'] : [], actor: $staff);
        });

        Auth::guard('staff')->login($staff);
        $request->session()->regenerate();
    }

    private function useRecoveryCode(PlatformStaff $staff, string $code): bool
    {
        $hash = $this->hashCode($code);
        $codes = $staff->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $staff->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $this->audit->record('auth.recovery_code_used', $staff, new: ['remaining' => count($codes)], actor: $staff);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            static fn () => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /** Recovery codes are random enough that a plain SHA-256 is the right hash — bcrypt would only slow sign-in. */
    private function hashCode(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }
}
