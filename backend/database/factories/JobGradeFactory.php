<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JobGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobGrade>
 */
class JobGradeFactory extends Factory
{
    protected $model = JobGrade::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('G???')),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
