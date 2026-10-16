<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant-aware password resets.
 *
 * Laravel's own broker keys tokens on the email address alone, which cannot
 * work once the same address may exist at several schools — School A's reset
 * link would overwrite School B's. This is a direct replacement keyed on
 * (tenant_id, email), using the same table.
 *
 * Tokens are stored hashed. A leaked database backup therefore does not hand
 * over live reset links.
 */
final readonly class PasswordResetService
{
    private const TABLE = 'password_reset_tokens';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Always behaves identically whether or not the address exists — the
     * endpoint must not reveal who has an account at a given school.
     */
    public function sendResetLink(string $email, ?int $tenantId): void
    {
        $user = $this->findUser($email, $tenantId);

        if ($user === null) {
            return;
        }

        // One live link at a time; requesting a new one invalidates the old.
        if ($this->recentlyRequested($user)) {
            return;
        }

        $token = Str::random(64);

        $this->upsertToken($user, $token);

        $user->notify(new ResetPasswordNotification($token));

        $this->audit->logFor(AuditAction::PASSWORD_RESET_REQUESTED, 'Auth', $user->tenant_id, $user);
    }

    /**
     * A set-password token for an invited account — the back office's
     * school-owner invite. Same table and same /reset-password page as a
     * reset, but no throttle (staff may resend at once, which kills the old
     * link) and it lasts `platform.invite_days` while the account is still
     * invited (see isExpired()).
     */
    public function issueInvite(User $user): string
    {
        $token = Str::random(64);

        $this->upsertToken($user, $token);

        return $token;
    }

    public function reset(string $email, ?int $tenantId, string $token, string $password): bool
    {
        $user = $this->findUser($email, $tenantId);

        if ($user === null) {
            return false;
        }

        $record = DB::table(self::TABLE)
            ->where('email', $user->email)
            ->when(
                $user->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $user->tenant_id),
            )
            ->first();

        if ($record === null || ! Hash::check($token, $record->token)) {
            return false;
        }

        if ($this->isExpired($record->created_at, $user)) {
            $this->deleteToken($user);

            return false;
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ]);

            // Accepting an invite is what makes the account usable.
            if ($user->status === User::STATUS_INVITED) {
                $user->forceFill(['status' => User::STATUS_ACTIVE, 'email_verified_at' => $user->email_verified_at ?? now()]);
            }

            $user->save();

            // A password change must invalidate every other credential: API
            // tokens and sessions elsewhere are exactly what an attacker who
            // prompted this reset would be holding.
            $user->tokens()->delete();

            $this->deleteToken($user);
        });

        $this->audit->logFor(AuditAction::PASSWORD_CHANGE, 'Users', $user->tenant_id, $user);

        return true;
    }

    private function findUser(string $email, ?int $tenantId): ?User
    {
        return User::query()
            ->inTenant($tenantId)
            ->where('email', mb_strtolower(trim($email)))
            ->first();
    }

    private function recentlyRequested(User $user): bool
    {
        $record = DB::table(self::TABLE)
            ->where('email', $user->email)
            ->when(
                $user->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $user->tenant_id),
            )
            ->first();

        return $record !== null
            && Carbon::parse($record->created_at)->addSeconds($this->throttleSeconds())->isFuture();
    }

    private function upsertToken(User $user, string $token): void
    {
        $this->deleteToken($user);

        DB::table(self::TABLE)->insert([
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);
    }

    private function deleteToken(User $user): void
    {
        DB::table(self::TABLE)
            ->where('email', $user->email)
            ->when(
                $user->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $user->tenant_id),
            )
            ->delete();
    }

    private function isExpired(string $createdAt, User $user): bool
    {
        $minutes = $user->status === User::STATUS_INVITED
            ? (int) config('platform.invite_days', 7) * 24 * 60
            : (int) config('auth.passwords.users.expire', 60);

        return Carbon::parse($createdAt)->addMinutes($minutes)->isPast();
    }

    private function throttleSeconds(): int
    {
        return (int) config('auth.passwords.users.throttle', 60);
    }
}
