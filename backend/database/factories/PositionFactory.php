<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'role_id' => Role::factory(),
            'description' => fake()->sentence(),
            'status' => Position::STATUS_ACTIVE,
        ];
    }

    /**
     * See TeacherFactory::forTenant()'s docblock for why this exists and when
     * it's actually needed (BelongsToTenant already auto-stamps tenant_id
     * from the ambient TenantContext on a plain create()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Position $position) use ($tenant) {
            $position->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => Position::STATUS_INACTIVE]);
    }
}
