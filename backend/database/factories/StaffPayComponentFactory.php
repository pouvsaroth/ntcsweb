<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffPayComponent;
use App\Models\PayrollComponent;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffPayComponent>
 */
class StaffPayComponentFactory extends Factory
{
    protected $model = StaffPayComponent::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'payroll_component_id' => PayrollComponent::factory(),
            'amount' => 50,
            'recurrence' => StaffPayComponent::RECURRING,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ];
    }
}
