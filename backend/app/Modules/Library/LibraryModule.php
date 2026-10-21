<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Modules\Module;

/**
 * Books and their categories.
 * See docs/architecture/multi-product.md (module registry).
 */
final class LibraryModule extends Module
{
    public function code(): string
    {
        return 'library';
    }

    public function label(): string
    {
        return 'Library';
    }

    public function labelKm(): string
    {
        return 'បណ្ណាល័យ';
    }

    public function menus(): array
    {
        return [
            'books' => ['Books', 'សៀវភៅ'],
            'book-categories' => ['Book categories', 'ប្រភេទសៀវភៅ'],
        ];
    }
}
