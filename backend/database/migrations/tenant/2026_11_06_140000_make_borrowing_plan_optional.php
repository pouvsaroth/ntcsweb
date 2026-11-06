<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Borrowings > Loans: Months to pay and First payment are
 * optional. A loan without months has no fixed plan: paid on any date, any
 * amount, or interest only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedSmallInteger('term_months')->nullable()->change();
            $table->date('first_payment_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Loans saved without a plan would break NOT NULL; leave the columns optional.
    }
};
