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

    /** Owe joins here in their own steps (each with a migration adding the menu to plans). */
    public function menus(): array
    {
        return [
            'loan-customers' => ['Customers', 'អតិថិជន'],
            'savings' => ['Savings', 'សន្សំ'],
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
