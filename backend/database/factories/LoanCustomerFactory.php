<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LoanCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoanCustomer> */
class LoanCustomerFactory extends Factory
{
    protected $model = LoanCustomer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'gender' => fake()->randomElement(LoanCustomer::GENDERS),
            'phone' => fake()->numerify('0## ### ###'),
            'is_active' => true,
        ];
    }
}
