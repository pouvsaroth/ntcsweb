<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicProgram>
 */
class AcademicProgramFactory extends Factory
{
    protected $model = AcademicProgram::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('PRG???')),
            'name' => fake()->words(2, true),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AcademicProgram $program) use ($tenant) {
            $program->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
