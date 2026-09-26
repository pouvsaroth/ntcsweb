<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data fix: InvoiceService::deriveStatus() used to require a total
 * above 0 before calling an invoice PAID, so a 100%-discounted invoice
 * (total 0, balance 0) was left ISSUED/OVERDUE and showed up in the unpaid
 * list. deriveStatus() now treats it as settled; this brings the existing
 * rows in line. Closed (CANCELLED/VOID) and DRAFT invoices are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->where('total', 0)
            ->where('balance', '<=', 0)
            ->whereIn('status', ['ISSUED', 'OVERDUE', 'PARTIALLY_PAID'])
            ->update(['status' => 'PAID']);
    }

    public function down(): void
    {
        // Irreversible on purpose — the previous status was wrong.
    }
};
