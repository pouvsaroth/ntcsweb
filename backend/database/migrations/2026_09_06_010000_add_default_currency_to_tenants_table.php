<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which currency the admin Dashboard and Billing Dashboard convert mixed
 * USD/KHR totals into before summing — see CurrencyConversionService and
 * the currency_rates table (next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('default_currency', 3)->default('USD')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('default_currency');
        });
    }
};
