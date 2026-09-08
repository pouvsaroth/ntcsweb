<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetHistory;
use App\Support\Assets\AssetHistoryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetHistory>
 */
class AssetHistoryFactory extends Factory
{
    protected $model = AssetHistory::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'event_type' => AssetHistoryEvent::CREATED,
            'description' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }

    public function event(string $eventType): static
    {
        return $this->state(['event_type' => $eventType]);
    }
}
