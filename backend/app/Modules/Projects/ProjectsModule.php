<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Modules\Module;

/**
 * Projects and their boards.
 * See docs/architecture/multi-product.md (module registry).
 */
final class ProjectsModule extends Module
{
    public function code(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'Projects';
    }

    public function labelKm(): string
    {
        return 'គម្រោង';
    }

    public function menus(): array
    {
        return [
            'projects' => ['Projects', 'គម្រោង'],
        ];
    }
}
