<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ResignationRequest;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResignationRequest>
 */
class ResignationRequestFactory extends Factory
{
    protected $model = ResignationRequest::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'resignation_date' => fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d'),
            'reason' => fake()->sentence(8),
            'status' => ResignationRequest::STATUS_PENDING,
        ];
    }

    public function forStaff(Staff $staff): static
    {
        return $this->state(['staff_id' => $staff->getKey()]);
    }

    public function approved(): static
    {
        return $this->state(['status' => ResignationRequest::STATUS_APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ResignationRequest::STATUS_REJECTED]);
    }
}
