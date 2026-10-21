<?php

declare(strict_types=1);

namespace App\Modules\Website;

use App\Modules\Module;

/**
 * The school's public website pieces still edited in the console.
 * See docs/architecture/multi-product.md (module registry).
 */
final class WebsiteModule extends Module
{
    public function code(): string
    {
        return 'website';
    }

    public function label(): string
    {
        return 'Website';
    }

    public function labelKm(): string
    {
        return 'គេហទំព័រ';
    }

    public function menus(): array
    {
        return [
            'videos' => ['Video lessons', 'មេរៀនវីដេអូ'],
            'contact-messages' => ['Contact messages', 'សារទំនាក់ទំនង'],
        ];
    }
}
