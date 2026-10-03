<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DownloadFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadFolder>
 */
class DownloadFolderFactory extends Factory
{
    protected $model = DownloadFolder::class;

    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->words(2, true),
            'sort_order' => 0,
            'status' => DownloadFolder::STATUS_ACTIVE,
        ];
    }

    public function inside(DownloadFolder $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => DownloadFolder::STATUS_INACTIVE]);
    }
}
