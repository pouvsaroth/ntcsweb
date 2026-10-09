<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Each Day / From / To row of a shift gets its own unpaid
 * break. Nullable: a row saved before this column (null) keeps using the
 * shift's own break_minutes, exactly as it did — see Shift::breakOn().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_days', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_minutes')->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('shift_days', function (Blueprint $table) {
            $table->dropColumn('break_minutes');
        });
    }
};
