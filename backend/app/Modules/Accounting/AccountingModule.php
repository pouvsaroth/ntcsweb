<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Module;

/**
 * Chart of accounts, income, expenses, transactions, financial reports.
 * See docs/architecture/multi-product.md (module registry).
 */
final class AccountingModule extends Module
{
    public function code(): string
    {
        return 'accounting';
    }

    public function label(): string
    {
        return 'Accounting';
    }

    public function labelKm(): string
    {
        return 'គណនេយ្យ';
    }

    public function menus(): array
    {
        return [
            'accounting' => ['Accounting', 'គណនេយ្យ'],
            'accounts' => ['Chart of accounts', 'តារាងគណនី'],
            'income' => ['Income', 'ចំណូល'],
            'expense' => ['Expenses', 'ចំណាយ'],
            'transactions' => ['Transactions', 'ប្រតិបត្តិការ'],
            'reports' => ['Financial reports', 'របាយការណ៍ហិរញ្ញវត្ថុ'],
        ];
    }
}
