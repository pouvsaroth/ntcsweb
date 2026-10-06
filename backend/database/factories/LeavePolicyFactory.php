<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeavePolicy>
 */
class LeavePolicyFactory extends Factory
{
    protected $model = LeavePolicy::class;

    public function definition(): array
    {
        return [
            'leave_type_id' => LeaveType::factory(),
            'name' => 'Standard',
            'days_per_year' => 18,
        ];
    }
}
