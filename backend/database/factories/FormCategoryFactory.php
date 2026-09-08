<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormCategory>
 */
class FormCategoryFactory extends Factory
{
    protected $model = FormCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'order' => 0,
            'is_active' => true,
        ];
    }
}
