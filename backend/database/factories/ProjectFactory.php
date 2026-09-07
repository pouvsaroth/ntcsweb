<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->catchPhrase(),
            'description' => fake()->optional()->sentence(),
            'status' => Project::STATUS_ACTIVE,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Project $project) use ($tenant) {
            $project->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
