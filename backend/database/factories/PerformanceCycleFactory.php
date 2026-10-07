<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PerformanceCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceCycle>
 */
class PerformanceCycleFactory extends Factory
{
    protected $model = PerformanceCycle::class;

    public function definition(): array
    {
        return [
            'name' => '2026 annual',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
        ];
    }
}
