<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Kpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kpi>
 */
class KpiFactory extends Factory
{
    protected $model = Kpi::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('KP???')),
            'name' => 'Class pass rate',
            'unit' => '%',
            'target' => 90,
        ];
    }
}
