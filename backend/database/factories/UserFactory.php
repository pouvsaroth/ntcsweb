<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            // Explicitly null rather than omitted: Model::shouldBeStrict() (see
            // AppServiceProvider) throws on an attribute key that is absent
            // from a hydrated model, which a factory-built row otherwise would
            // be for every nullable column it doesn't mention.
            'locale' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function withPhone(string $phone): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => \App\Support\Auth\PhoneNumber::normalize($phone) ?? $phone,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_SUSPENDED,
        ]);
    }

    /**
     * tenant_id is deliberately excluded from User::$fillable so it can never
     * be mass-assigned from request input; the factory has to set it the same
     * way application code does — via forceFill, after the model is built.
     */
    public function forTenant(Tenant|int|null $tenant): static
    {
        return $this->afterMaking(function (User $user) use ($tenant) {
            $user->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    /**
     * A platform super admin: no school, holding the platform super-admin role.
     * Requires RolePermissionSeeder to have run so that role exists.
     */
    public function superAdmin(): static
    {
        return $this->forTenant(null)->afterCreating(function (User $user) {
            $role = \App\Models\Role::query()->platform()->where('slug', \App\Models\Role::SUPER_ADMIN)->first();

            if ($role !== null) {
                $user->attachRoles($role);
            }
        });
    }
}
