<?php

declare(strict_types=1);

namespace App\Modules\Fund;

use App\Modules\Module;

/**
 * The Fund menu: separate pots of the school's money (Building fund,
 * Scholarship fund, Staff welfare ...), each with a type, money in and
 * money out posted to the ledger, and its balance.
 * See docs/architecture/multi-product.md (module registry).
 */
final class FundModule extends Module
{
    public function code(): string
    {
        return 'fund';
    }

    public function label(): string
    {
        return 'Fund';
    }

    public function labelKm(): string
    {
        return 'មូលនិធិ';
    }

    /** A new menu needs its permissions and a migration adding it to plans that list menus. */
    public function menus(): array
    {
        return [
            'funds' => ['Funds', 'មូលនិធិ'],
        ];
    }
}
