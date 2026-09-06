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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->date('effective_date');
            $table->decimal('khr_per_usd', 12, 4);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'effective_date']);
            $table->index(['tenant_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
