<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
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

        $this->audit->logFor('auth.password_reset_requested', $user->tenant_id, $user);
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

        if ($this->isExpired($record->created_at)) {
            $this->deleteToken($user);

            return false;
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // A password change must invalidate every other credential: API
            // tokens and sessions elsewhere are exactly what an attacker who
            // prompted this reset would be holding.
            $user->tokens()->delete();

            $this->deleteToken($user);
        });

        $this->audit->logFor('auth.password_reset', $user->tenant_id, $user);

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

    private function isExpired(string $createdAt): bool
    {
        return Carbon::parse($createdAt)
            ->addMinutes((int) config('auth.passwords.users.expire', 60))
            ->isPast();
    }

    private function throttleSeconds(): int
    {
        return (int) config('auth.passwords.users.throttle', 60);
    }
}
