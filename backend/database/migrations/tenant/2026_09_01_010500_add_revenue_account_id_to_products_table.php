<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-product override of which Revenue account a sale posts to
 * (e.g. a specific Product pointed at a custom account instead of the
 * type-based default) — see RevenueAccountResolver.
 *
 * No DB-level foreign key: `accounts` stays in the central database while
 * `products` lives in each school's own per-tenant database (see
 * database/migrations/tenant), and a cross-database foreign key isn't
 * possible in Postgres regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('revenue_account_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('revenue_account_id');
        });
    }
};
