<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an Expense trace back to whatever created it (e.g. an AssetRepair) —
 * mirrors `financial_transactions.reference_type/reference_id` exactly.
 * Purely additive: every existing Expense simply has both columns null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->nullableMorphs('reference');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropMorphs('reference');
        });
    }
};
