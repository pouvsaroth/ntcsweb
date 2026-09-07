<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTask>
 */
class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_column_id' => ProjectColumn::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'priority' => ProjectTask::PRIORITY_MEDIUM,
            'order' => 0,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     * Pass explicit `project_id`/`project_column_id` alongside this when the
     * default nested factories would otherwise be created without a tenant
     * to attach to (e.g. inside TenantContext::withoutTenancy()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ProjectTask $task) use ($tenant) {
            $task->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
