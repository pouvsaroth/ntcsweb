<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoursePackage>
 */
class CoursePackageFactory extends Factory
{
    protected $model = CoursePackage::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('PKG???')),
            'name' => fake()->words(3, true),
            'academic_program_id' => AcademicProgram::factory(),
            'price' => fake()->randomFloat(2, 10, 200),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (CoursePackage $package) use ($tenant) {
            $package->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forProgram(AcademicProgram $program): static
    {
        return $this->state(['academic_program_id' => $program->getKey()]);
    }
}
