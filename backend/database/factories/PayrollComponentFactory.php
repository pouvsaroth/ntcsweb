<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayrollComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollComponent>
 */
class PayrollComponentFactory extends Factory
{
    protected $model = PayrollComponent::class;

    public function definition(): array
    {
        return [
            'kind' => PayrollComponent::KIND_ALLOWANCE,
            'code' => strtoupper(fake()->unique()->lexify('PC???')),
            'name' => 'Transport allowance',
            'calculation' => PayrollComponent::CALCULATION_FIXED,
        ];
    }
}
