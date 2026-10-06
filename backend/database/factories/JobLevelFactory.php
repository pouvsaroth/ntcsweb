<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JobLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobLevel>
 */
class JobLevelFactory extends Factory
{
    protected $model = JobLevel::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('L???')),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
