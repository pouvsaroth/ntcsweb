<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lender> */
class LenderFactory extends Factory
{
    protected $model = Lender::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'type' => 'company',
            'is_active' => true,
        ];
    }
}
