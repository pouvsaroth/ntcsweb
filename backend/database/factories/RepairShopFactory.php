<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RepairShop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepairShop>
 */
class RepairShopFactory extends Factory
{
    protected $model = RepairShop::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Repair',
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'specialization' => fake()->randomElement(['Computer / Laptop', 'Printer', 'Network Equipment', 'General Electronics']),
            'is_active' => true,
        ];
    }
}
