<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Base Data ("DISCOUNT_REASON") LookupValue code, same plain-string
 * pattern as Student.gender/guardian_type — the category is implicit by
 * convention, not a schema-level FK. `discount` already carries the actual
 * amount; this just records why, for reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('discount_reason', 50)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('discount_reason');
        });
    }
};
