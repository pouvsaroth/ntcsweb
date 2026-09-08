<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KHR per 1 USD, by date — the only pair this app ever needs (Invoice/
 * CoursePackage/FinancialTransaction currencies are always USD or KHR).
 * Dashboard totals convert an amount using the rate whose `effective_date`
 * is the most recent one on or before that amount's own transaction date —
 * see CurrencyConversionService::rateForDate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();

            $table->date('effective_date');
            $table->decimal('khr_per_usd', 12, 4);

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('effective_date');
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
