<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Teacher;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        return [
            'employee_code' => 'T-'.fake()->unique()->numerify('####'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'specialization' => fake()->randomElement(['Excel', 'Programming', 'English', 'Design', 'Accounting']),
            'bio' => fake()->sentence(),
            'hire_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'status' => Teacher::STATUS_ACTIVE,
            // Nullable and unset by default; the model doesn't require a
            // linked login account. Explicit null (not omitted): see
            // UserFactory's note on Model::shouldBeStrict().
            'user_id' => null,
        ];
    }

    /**
     * tenant_id is excluded from Teacher::$fillable (BelongsToTenant's own
     * rule — see the trait's docblock), so a plain state() array would trip
     * the same MassAssignmentException a request body would. This needs
     * forceFill, exactly like UserFactory::forTenant()/RoleFactory::forTenant().
     *
     * Usually unnecessary in tests: BelongsToTenant's `creating` hook already
     * stamps tenant_id from TenantContext automatically, so
     * `$this->actingInTenant($tenant)` followed by a plain
     * `Teacher::factory()->create()` is normally all a test needs. This
     * exists for the cases that must pin a specific tenant regardless of
     * ambient context (e.g. building fixtures for two tenants side by side).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Teacher $teacher) use ($tenant) {
            $teacher->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => Teacher::STATUS_INACTIVE]);
    }
}
