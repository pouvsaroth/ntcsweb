<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PerformanceGoal;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceGoal>
 */
class PerformanceGoalFactory extends Factory
{
    protected $model = PerformanceGoal::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'title' => 'Raise the pass rate',
        ];
    }
}
