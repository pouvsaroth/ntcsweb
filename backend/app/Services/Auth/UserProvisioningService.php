<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use App\Support\Auth\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * Auto-provisions the User account behind a newly created Student or Staff
 * record — see StudentController::store() and StaffController::store().
 *
 * Deliberately does not open its own transaction: the caller always has a
 * second write (the Student/Staff row itself, plus a foreign key back to the
 * user it just created) that must succeed or fail together with this one, so
 * the caller wraps both in a single DB::transaction().
 *
 * The default password is the account's own phone number — chosen deliberately
 * (not a random string) so a school can hand out logins without any
 * email/SMS channel to deliver a generated one through (this project has
 * neither). It is still returned once in the creating admin's own API
 * response for confirmation, but it is also just "their phone number", which
 * the admin already knows without it — encourage a password change on first
 * login if that matters for a given school.
 *
 * Not `final`/`readonly`, unlike its sibling services — StaffController's
 * transaction test mocks this to simulate a mid-transaction failure, which
 * Mockery cannot do to a final or readonly class.
 */
class UserProvisioningService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{name: string, email?: string|null, phone: string}  $attributes
     * @return array{user: User, temporary_password: string}
     */
    public function provision(array $attributes, Role $role): array
    {
        // Normalized, not raw: this is what AuthService compares a login
        // attempt's phone against (see PhoneNumber's own docblock), so
        // storing the raw, still-formatted input here would make "log in
        // with your phone number as your password" fail for anyone who
        // typed it with spaces or dashes.
        $phone = PhoneNumber::normalize($attributes['phone']) ?? $attributes['phone'];

        // tenant_id is guarded on User (see the model docblock — it
        // deliberately doesn't use BelongsToTenant, so nothing stamps this
        // automatically), the same reason UserFactory::forTenant() and
        // RoleController::store() both forceFill it explicitly.
        $user = new User([
            'name' => $attributes['name'],
            'email' => $attributes['email'] ?? null,
            'phone' => $phone,
            'password' => Hash::make($phone),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->forceFill(['tenant_id' => $this->context->idOrFail()]);
        $user->save();

        $user->attachRoles($role);

        return ['user' => $user, 'temporary_password' => $phone];
    }
}
