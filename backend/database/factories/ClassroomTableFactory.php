<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassroomTable>
 */
class ClassroomTableFactory extends Factory
{
    protected $model = ClassroomTable::class;

    public function definition(): array
    {
        return [
            'classroom_id' => Classroom::factory(),
            'name' => 'Table '.fake()->unique()->numberBetween(1, 1000),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ClassroomTable $classroomTable) use ($tenant) {
            $classroomTable->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
