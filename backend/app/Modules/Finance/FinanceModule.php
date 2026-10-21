<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Modules\Module;

/**
 * School billing: invoices and payments of students, receipts, the shop price list.
 * See docs/architecture/multi-product.md (module registry).
 */
final class FinanceModule extends Module
{
    public function code(): string
    {
        return 'finance';
    }

    public function label(): string
    {
        return 'Finance';
    }

    public function labelKm(): string
    {
        return 'ហិរញ្ញវត្ថុ';
    }

    public function menus(): array
    {
        return [
            'invoices' => ['Invoices', 'វិក្កយបត្រ'],
            'payments' => ['Payments', 'ការបង់ប្រាក់'],
            'receipts' => ['Receipts', 'បង្កាន់ដៃ'],
            'billing-reports' => ['Billing reports', 'របាយការណ៍វិក្កយបត្រ'],
            'products' => ['Products', 'ផលិតផល'],
        ];
    }

    public function dashboardPermissions(): array
    {
        return [
            'dashboard.cards.student-payment',
            'dashboard.cards.invoices',
            'dashboard.finance.view',
            'dashboard.payment-alerts.view',
        ];
    }
}
