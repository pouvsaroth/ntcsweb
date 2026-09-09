<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Staff;
use App\Models\StaffStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffStatusHistory>
 */
class StaffStatusHistoryFactory extends Factory
{
    protected $model = StaffStatusHistory::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'from_status' => Staff::STATUS_ACTIVE,
            'to_status' => Staff::STATUS_SUSPENDED,
            'reason' => null,
            'requested_date' => null,
            'effective_date' => null,
        ];
    }

    public function forStaff(Staff $staff): static
    {
        return $this->state(['staff_id' => $staff->getKey()]);
    }
}
