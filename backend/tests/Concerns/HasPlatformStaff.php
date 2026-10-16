<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformStaff;
use App\Platform\Support\Totp;

/** Back-office accounts for platform tests — see docs/backoffice/. */
trait HasPlatformStaff
{
    /**
     * An active staff account with 2FA enrolled and its password already
     * changed — the state every platform route past sign-in expects.
     *
     * @param  list<string>|null  $permissions  null = the Owner role
     */
    protected function platformStaff(?array $permissions = null, array $attributes = []): PlatformStaff
    {
        $staff = new PlatformStaff([
            'name' => $attributes['name'] ?? 'Staff '.fake()->unique()->numberBetween(1, 99999),
            'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
        ]);
        $staff->forceFill([
            'password' => $attributes['password'] ?? 'Correct-horse-9',
            'status' => $attributes['status'] ?? PlatformStaff::STATUS_ACTIVE,
            'must_change_password' => $attributes['must_change_password'] ?? false,
            'two_factor_secret' => array_key_exists('two_factor_secret', $attributes) ? $attributes['two_factor_secret'] : Totp::generateSecret(),
            'two_factor_confirmed_at' => array_key_exists('two_factor_confirmed_at', $attributes) ? $attributes['two_factor_confirmed_at'] : now(),
        ])->save();

        $role = $permissions === null
            ? PlatformRole::query()->where('is_owner', true)->firstOrFail()
            : PlatformRole::query()->create(['name' => 'Role '.fake()->unique()->numberBetween(1, 99999), 'permissions' => $permissions]);

        $staff->roles()->attach($role);

        return $staff->fresh();
    }

    protected function actingAsStaff(PlatformStaff $staff): static
    {
        return $this->actingAs($staff, 'staff');
    }

    protected function currentCode(PlatformStaff $staff, int $offsetSteps = 0): string
    {
        return Totp::codeAt($staff->two_factor_secret, Totp::step(now()->getTimestamp()) + $offsetSteps);
    }
}
