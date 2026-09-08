<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetDocument>
 */
class AssetDocumentFactory extends Factory
{
    protected $model = AssetDocument::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'type' => AssetDocument::OTHER,
            'file_path' => 'asset-documents/'.fake()->uuid().'.pdf',
            'file_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
        ];
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }
}
