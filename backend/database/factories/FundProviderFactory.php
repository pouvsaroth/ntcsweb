<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FundProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FundProvider> */
class FundProviderFactory extends Factory
{
    protected $model = FundProvider::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('0## ### ###'),
            'is_active' => true,
        ];
    }
}
