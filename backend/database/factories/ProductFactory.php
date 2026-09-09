<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Support\Billing\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'code' => 'PROD-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'type' => ProductType::OTHER,
            'price' => fake()->randomFloat(2, 5, 200),
            'is_active' => true,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
