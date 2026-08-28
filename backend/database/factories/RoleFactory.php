<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => null,
            'level' => fake()->numberBetween(10, 60),
        ];
    }

    /**
     * tenant_id and is_system are excluded from Role::$fillable (see the model
     * docblock), so — exactly like UserFactory::forTenant() — this has to set
     * them via forceFill after the model is built rather than through a normal
     * state() array, which goes through the same guarded fill() as a request.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Role $role) use ($tenant) {
            $role->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function platform(): static
    {
        return $this->afterMaking(function (Role $role) {
            $role->forceFill(['tenant_id' => null]);
        });
    }

    public function system(): static
    {
        return $this->afterMaking(function (Role $role) {
            $role->forceFill(['is_system' => true]);
        });
    }

    public function level(int $level): static
    {
        return $this->state(['level' => $level]);
    }
}
