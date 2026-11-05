<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseEntity> */
class ExpenseEntityFactory extends Factory
{
    protected $model = ExpenseEntity::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}
