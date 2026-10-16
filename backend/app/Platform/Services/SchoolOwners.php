<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\SchoolOwnerInviteNotification;
use App\Platform\Models\School;
use App\Platform\Support\InviteLink;
use App\Platform\Support\PlatformAudit;
use App\Services\Auth\PasswordResetService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A school's owner: its first School Admin, invited by email. The back office
 * creates exactly this one account per school and never sets its password —
 * the owner invites everyone else from the school app.
 */
final class SchoolOwners
{
    public function __construct(
        private readonly PasswordResetService $passwords,
        private readonly PlatformAudit $audit,
        private readonly InviteLink $inviteLink,
    ) {}

    /**
     * The owner's account, created invited (no usable password) if it is not
     * there yet. Safe to call again — the provisioning job does, on retry.
     */
    public function ensureAccount(Tenant $tenant, string $name, string $email): User
    {
        $email = Str::lower(trim($email));

        $user = User::query()->inTenant($tenant->getKey())->where('email', $email)->first();

        if ($user === null) {
            $user = new User(['name' => $name, 'email' => $email]);
            $user->forceFill([
                'tenant_id' => $tenant->getKey(),
                // Random and never shown: the invite link is the only way in.
                'password' => Str::random(64),
                'status' => User::STATUS_INVITED,
            ])->save();
        }

        School::for($tenant)->forceFill(['owner_user_id' => $user->getKey()])->save();

        return $user;
    }

    /** Give the owner the School Admin role — it exists once the school is seeded. */
    public function grantSchoolAdmin(Tenant $tenant, User $user): void
    {
        $role = Role::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('slug', Role::SCHOOL_ADMIN)
            ->first()
            ?? throw new \RuntimeException("School {$tenant->slug} has no School Admin role — it was not seeded.");

        if (! $user->roles()->whereKey($role->getKey())->exists()) {
            $user->attachRoles($role);
        }
    }

    /** Send (or send again — the old link stops working) the set-password link by email. */
    public function invite(Tenant $tenant): void
    {
        $this->issue($tenant, byEmail: true);
    }

    /**
     * A fresh set-password link for staff to copy and send another way
     * (Telegram, SMS…) when email is not an option. Not emailed. Like a
     * resend, the previous link stops working — tokens are stored hashed, so
     * an old link can never be shown again.
     */
    public function copyableLink(Tenant $tenant): string
    {
        return $this->issue($tenant, byEmail: false);
    }

    private function issue(Tenant $tenant, bool $byEmail): string
    {
        $school = School::for($tenant);
        $owner = $school->owner;

        if ($owner === null) {
            throw ValidationException::withMessages(['owner' => __('This school has no owner yet.')]);
        }
        if ($owner->status !== User::STATUS_INVITED) {
            throw ValidationException::withMessages(['owner' => __('The owner has already accepted the invite.')]);
        }
        if (! $tenant->isActive()) {
            throw ValidationException::withMessages(['owner' => __('Invites go out once the school is active.')]);
        }

        $token = $this->passwords->issueInvite($owner);

        if ($byEmail) {
            $owner->notify(new SchoolOwnerInviteNotification($token));
        }

        $school->forceFill(['invite_sent_at' => now()])->save();
        $this->audit->record(
            $byEmail ? 'school.owner_invited' : 'school.owner_invite_link_copied',
            $tenant,
            new: ['email' => $owner->email],
            tenantId: $tenant->getKey(),
        );

        return $this->inviteLink->for($owner, $token);
    }

    /**
     * A different person becomes the owner: a new invited School Admin. The
     * previous owner's account is left exactly as it is — removing people
     * is the school's own business.
     */
    public function change(Tenant $tenant, string $name, string $email): User
    {
        $previous = School::for($tenant)->owner;
        $user = $this->ensureAccount($tenant, $name, $email);
        $this->grantSchoolAdmin($tenant, $user);

        $this->audit->record('school.owner_changed', $tenant, ['email' => $previous?->email], ['email' => $user->email], tenantId: $tenant->getKey());

        if ($user->status === User::STATUS_INVITED) {
            $this->invite($tenant);
        }

        return $user;
    }
}
