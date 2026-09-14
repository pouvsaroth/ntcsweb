<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The one place a UserNotification is ever created — see that model's own
 * docblock for why `type`/`data` rather than pre-rendered text.
 */
final class NotificationService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(User $recipient, string $type, array $data, ?string $link = null): UserNotification
    {
        return UserNotification::query()->create([
            'recipient_id' => $recipient->getKey(),
            'type' => $type,
            'data' => $data,
            'link' => $link,
        ]);
    }

    /**
     * @param  iterable<User>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function notifyMany(iterable $recipients, string $type, array $data, ?string $link = null): void
    {
        // Deduped by user id — e.g. a school admin who is also the assigned
        // teacher would otherwise be notified twice for the same event.
        Collection::make($recipients)
            ->unique(fn (User $user) => $user->getKey())
            ->each(fn (User $user) => $this->notify($user, $type, $data, $link));
    }

    /**
     * Every active user in the current tenant holding a given permission —
     * e.g. "who can approve this." A permission (not a fixed role) so a
     * tenant that customizes its own role/permission matrix still gets the
     * right people notified, not whoever happened to hold a role by default.
     *
     * @return Collection<int, User>
     */
    public function usersWithPermission(string $permission): Collection
    {
        return User::query()
            ->inTenant($this->context->getOrFail())
            ->active()
            ->whereHas('roles.permissions', fn ($query) => $query->where('slug', $permission))
            ->get();
    }

    /**
     * Every active user in the current tenant holding a given system role —
     * for a recipient group with no permission of its own to key off, e.g.
     * "every Staff-role account" as a stand-in for "the receptionist" (no
     * fixed Receptionist role/position exists in this app).
     *
     * @return Collection<int, User>
     */
    public function usersWithRole(string $roleSlug): Collection
    {
        return User::query()
            ->inTenant($this->context->getOrFail())
            ->active()
            ->whereHas('roles', fn ($query) => $query->where('slug', $roleSlug))
            ->get();
    }
}
