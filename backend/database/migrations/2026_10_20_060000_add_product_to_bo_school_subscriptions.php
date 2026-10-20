<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer may hold several systems (docs/backoffice/stage-9-products.md, 9b):
 * each subscription records its system, and "one live subscription" becomes
 * one live subscription per customer per system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_school_subscriptions', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('bo_products')->restrictOnDelete();
        });

        // Each row takes its plan's system.
        DB::statement('UPDATE bo_school_subscriptions s SET product_id = p.product_id FROM bo_plans p WHERE p.id = s.plan_id');

        DB::statement('DROP INDEX IF EXISTS bo_school_subscriptions_one_live');
        DB::statement("CREATE UNIQUE INDEX bo_school_subscriptions_one_live_per_product ON bo_school_subscriptions (tenant_id, product_id) WHERE status IN ('trial', 'active')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bo_school_subscriptions_one_live_per_product');
        DB::statement("CREATE UNIQUE INDEX bo_school_subscriptions_one_live ON bo_school_subscriptions (tenant_id) WHERE status IN ('trial', 'active')");
        Schema::table('bo_school_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
