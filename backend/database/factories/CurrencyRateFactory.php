<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CurrencyRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyRate>
 */
class CurrencyRateFactory extends Factory
{
    protected $model = CurrencyRate::class;

    public function definition(): array
    {
        return [
            'effective_date' => fake()->unique()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'khr_per_usd' => fake()->randomFloat(2, 4000, 4200),
        ];
    }
}
