<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Modules\Module;

/**
 * The Marketing menu: campaigns (channel, dates, budget and real cost) that
 * Front Office inquiries are linked to, so the school sees which campaign
 * brought leads and students. See docs/architecture/multi-product.md.
 */
final class MarketingModule extends Module
{
    public function code(): string
    {
        return 'marketing';
    }

    public function label(): string
    {
        return 'Marketing';
    }

    public function labelKm(): string
    {
        return 'ទីផ្សារ';
    }

    /** A new menu needs its permissions and a migration adding it to plans that list menus. */
    public function menus(): array
    {
        return [
            'campaigns' => ['Campaigns', 'យុទ្ធនាការ'],
        ];
    }

    public function dashboardPermissions(): array
    {
        return [
            'dashboard.cards.marketing',
        ];
    }
}
