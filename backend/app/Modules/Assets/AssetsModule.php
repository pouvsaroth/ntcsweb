<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use App\Modules\Module;

/**
 * Assets, their issues, maintenance and repairs.
 * See docs/architecture/multi-product.md (module registry).
 */
final class AssetsModule extends Module
{
    public function code(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Assets';
    }

    public function labelKm(): string
    {
        return 'ទ្រព្យសម្បត្តិ';
    }

    public function menus(): array
    {
        return [
            'assets' => ['Assets', 'ទ្រព្យសម្បត្តិ'],
            'my-assets' => ['My assets', 'ទ្រព្យសម្បត្តិរបស់ខ្ញុំ'],
        ];
    }
}
