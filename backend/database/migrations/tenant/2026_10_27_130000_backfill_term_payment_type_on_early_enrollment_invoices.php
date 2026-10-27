<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment invoices with no payment type: the ones imported from the old
 * system (LEGACY-…) and the first new ones made before the payment_type
 * column existed (added 2026-09-15). The school owner confirmed these
 * students all pay by term (2026-10-27), so they get `term`. Only invoices
 * linked to an enrollment and created before 2026-09-16 are touched; every
 * invoice since then already carries the type chosen at enrollment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE invoices
            SET payment_type = 'term'
            WHERE payment_type IS NULL
              AND created_at < '2026-09-16'
              AND EXISTS (
                  SELECT 1 FROM invoice_items
                  WHERE invoice_items.invoice_id = invoices.id
                    AND invoice_items.reference_type LIKE '%Enrollment'
              )
        SQL);
    }

    public function down(): void
    {
        // Not reversible: which rows were filled in here is not recorded.
    }
};
