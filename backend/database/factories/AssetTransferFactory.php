<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetTransfer>
 */
class AssetTransferFactory extends Factory
{
    protected $model = AssetTransfer::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'transfer_date' => now()->toDateString(),
        ];
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }
}
