<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'image_path' => 'tenants/0/promotions/'.fake()->uuid().'.jpg',
            'title' => fake()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 10),
            'status' => Promotion::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => Promotion::STATUS_INACTIVE]);
    }
}
