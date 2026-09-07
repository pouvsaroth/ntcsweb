<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectColumn>
 */
class ProjectColumnFactory extends Factory
{
    protected $model = ProjectColumn::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['To Do', 'In Progress', 'Review', 'Done']),
            'color' => null,
            'order' => 0,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     * Pass an explicit `project_id` alongside this when the default nested
     * Project::factory() would otherwise be created without a tenant to
     * attach to (e.g. inside TenantContext::withoutTenancy()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ProjectColumn $column) use ($tenant) {
            $column->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
