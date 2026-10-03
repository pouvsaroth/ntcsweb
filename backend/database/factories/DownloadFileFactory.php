<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadFile>
 */
class DownloadFileFactory extends Factory
{
    protected $model = DownloadFile::class;

    public function definition(): array
    {
        return [
            'download_folder_id' => DownloadFolder::factory(),
            'name' => fake()->word(),
            'file_path' => 'tenants/0/downloads/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'sort_order' => 0,
        ];
    }
}
