<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LookupCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupCategory>
 */
class LookupCategoryFactory extends Factory
{
    protected $model = LookupCategory::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('CATEGORY_???')),
            'name' => fake()->words(2, true),
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
