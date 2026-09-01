<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-product override of which Revenue account a sale posts to
 * (e.g. a specific Product pointed at a custom account instead of the
 * type-based default) — see RevenueAccountResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->after('type')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_account_id');
        });
    }
};
