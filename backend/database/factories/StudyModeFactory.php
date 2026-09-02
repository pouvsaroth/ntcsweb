<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StudyMode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyMode>
 */
class StudyModeFactory extends Factory
{
    protected $model = StudyMode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('MODE???')),
            'name' => fake()->words(2, true),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (StudyMode $mode) use ($tenant) {
            $mode->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
