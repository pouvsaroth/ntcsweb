<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data fix, requested by the school: invoices were being stamped
 * with the wrong currency, so a Riel fee showed as e.g. "80,000 USD". No
 * real course fee is ever above 1,000 USD or below 1,000 KHR, so the amount
 * itself tells the currency apart: total > 1000 is KHR, total < 1000 is USD.
 * A total of exactly 0 or 1000 carries no signal and is left as-is.
 *
 * Payments have no currency column of their own (they're always in their
 * invoice's), but the ledger rows posted for them do — and
 * FinancialTransactionService::post() used to stamp every one of those USD
 * regardless, which is what inflated the dashboard's Monthly/Daily Income.
 * Every payment-linked row (the INCOME posting and any reversal of it, both
 * referencing the Payment) is re-stamped with its invoice's corrected
 * currency. Amounts are never changed, only the currency label.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->where('total', '>', 1000)->where('currency', '!=', 'KHR')->update(['currency' => 'KHR']);

        DB::table('invoices')->where('total', '>', 0)->where('total', '<', 1000)->where('currency', '!=', 'USD')->update(['currency' => 'USD']);

        DB::table('financial_transactions')
            ->where('reference_type', 'App\\Models\\Payment')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.id', 'financial_transactions.reference_id'))
            ->update([
                'currency' => DB::raw(
                    '(SELECT invoices.currency FROM payments JOIN invoices ON invoices.id = payments.invoice_id '
                    .'WHERE payments.id = financial_transactions.reference_id)'
                ),
            ]);
    }

    public function down(): void
    {
        // Irreversible on purpose — the previous values were wrong.
    }
};
