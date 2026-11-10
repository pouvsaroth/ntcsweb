<?php

declare(strict_types=1);

namespace App\Modules\Loan;

use App\Modules\Module;

/**
 * The Loan menu: customers, their savings, money customers owe the owner,
 * and Borrowings (money the owner owes; moved here from Accounting).
 * See docs/architecture/multi-product.md (module registry).
 */
final class LoanModule extends Module
{
    public function code(): string
    {
        return 'loan';
    }

    public function label(): string
    {
        return 'Loan';
    }

    public function labelKm(): string
    {
        return 'ប្រាក់កម្ចី';
    }

    /** A new menu needs its permissions and a migration adding it to plans that list menus. */
    public function menus(): array
    {
        return [
            'loan-customers' => ['Customers', 'អតិថិជន'],
            'savings' => ['Savings', 'សន្សំ'],
            'customer-loans' => ['Owe', 'ជំពាក់'],
            'borrowings' => ['Borrowings', 'ប្រាក់ខ្ចី'],
        ];
    }

    public function dashboardPermissions(): array
    {
        return [
            'dashboard.cards.borrowings',
        ];
    }
}
