<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSchedule>
 */
class ClassScheduleFactory extends Factory
{
    protected $model = ClassSchedule::class;

    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'day_of_week' => fake()->numberBetween(1, 7),
            'start_time' => '18:00:00',
            'end_time' => '20:00:00',
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ClassSchedule $schedule) use ($tenant) {
            $schedule->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forClass(SchoolClass $class): static
    {
        return $this->state(['class_id' => $class->getKey()]);
    }

    public function onDay(int $dayOfWeek): static
    {
        return $this->state(['day_of_week' => $dayOfWeek]);
    }

    public function at(string $start, string $end): static
    {
        return $this->state(['start_time' => $start, 'end_time' => $end]);
    }
}
