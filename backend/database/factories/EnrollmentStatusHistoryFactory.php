<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentStatusHistory>
 */
class EnrollmentStatusHistoryFactory extends Factory
{
    protected $model = EnrollmentStatusHistory::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'from_status' => Enrollment::STATUS_ACTIVE,
            'to_status' => Enrollment::STATUS_COMPLETED,
            'reason' => null,
            'effective_date' => null,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (EnrollmentStatusHistory $history) use ($tenant) {
            $history->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(['enrollment_id' => $enrollment->getKey()]);
    }
}
