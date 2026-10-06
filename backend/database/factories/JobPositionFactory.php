<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JobPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosition>
 */
class JobPositionFactory extends Factory
{
    protected $model = JobPosition::class;

    public function definition(): array
    {
        return [
            'title' => fake()->jobTitle(),
            'headcount' => 1,
            'employment_type' => 'full_time',
            'status' => JobPosition::STATUS_OPEN,
            'opened_on' => now()->toDateString(),
        ];
    }
}
