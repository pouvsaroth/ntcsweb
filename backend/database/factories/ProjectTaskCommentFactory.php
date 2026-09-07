<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTaskComment>
 */
class ProjectTaskCommentFactory extends Factory
{
    protected $model = ProjectTaskComment::class;

    public function definition(): array
    {
        return [
            'project_task_id' => ProjectTask::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ProjectTaskComment $comment) use ($tenant) {
            $comment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
