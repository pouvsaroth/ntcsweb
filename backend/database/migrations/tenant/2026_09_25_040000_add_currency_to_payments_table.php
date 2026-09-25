<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment is always in its invoice's currency (PaymentService::record()
 * copies it across from now on) — this stores it on the payment itself so a
 * payment row reads correctly on its own, and backfills every existing
 * payment from its invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('amount');
        });

        DB::table('payments')->update([
            'currency' => DB::raw('(SELECT invoices.currency FROM invoices WHERE invoices.id = payments.invoice_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
