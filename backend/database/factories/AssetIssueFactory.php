<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetIssue;
use App\Models\Tenant;
use App\Support\Assets\IssuePriority;
use App\Support\Assets\IssueStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetIssue>
 */
class AssetIssueFactory extends Factory
{
    protected $model = AssetIssue::class;

    public function definition(): array
    {
        return [
            'issue_number' => 'ISS-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'asset_id' => Asset::factory(),
            'reported_date' => now()->toDateString(),
            'priority' => IssuePriority::MEDIUM,
            'status' => IssueStatus::OPEN,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetIssue $issue) use ($tenant) {
            $issue->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }

    public function priority(string $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
