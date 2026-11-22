<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fund > Money in: "Provided by" can be a staff member (HRM > Staff) as well
 * as a Fund > Providers entry. Their name is also copied into `party`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('fund_provider_id')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_id');
        });
    }
};
