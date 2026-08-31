<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Program;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'subtitle' => fake()->words(2, true),
            'category' => fake()->randomElement(['Computer & IT', 'Business', 'Design', 'Language']),
            'level' => fake()->randomElement([Program::LEVEL_BEGINNER, Program::LEVEL_INTERMEDIATE, Program::LEVEL_ADVANCED]),
            'duration_label' => fake()->randomElement(['2 days', '3 months', '6 weeks']),
            'description' => fake()->paragraph(),
            'image_path' => null,
            'is_featured' => false,
            'sort_order' => fake()->numberBetween(0, 10),
            'status' => Program::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Program $program) use ($tenant) {
            $program->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => Program::STATUS_INACTIVE]);
    }
}
