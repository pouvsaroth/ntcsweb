<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Position;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'position_id' => Position::factory(),
            'employee_code' => 'S-'.fake()->unique()->numerify('####'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'hire_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'status' => Staff::STATUS_ACTIVE,
        ];
    }

    /**
     * `user_id` is excluded from Staff::$fillable (see the model docblock),
     * so linking one in a test needs forceFill — the same reason
     * RoleFactory/PositionFactory::forTenant() exist.
     */
    public function withUser(User $user): static
    {
        return $this->afterMaking(function (Staff $staff) use ($user) {
            $staff->forceFill(['user_id' => $user->id]);
        });
    }

    /**
     * See TeacherFactory::forTenant()'s docblock for why this exists and when
     * it's actually needed.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Staff $staff) use ($tenant) {
            $staff->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => Staff::STATUS_INACTIVE]);
    }
}
