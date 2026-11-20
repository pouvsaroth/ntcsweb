<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fund > Funds: the month a money in / out is for (first day of the month),
 * so monthly gifts can be filtered by month and copied to the next month.
 * Older entries take the month of their date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->date('for_month')->nullable()->after('transacted_on');
            $table->index(['fund_id', 'for_month']);
        });

        DB::connection($this->getConnection())
            ->statement("update fund_transactions set for_month = date_trunc('month', transacted_on)::date where for_month is null");
    }

    public function down(): void
    {
        Schema::table('fund_transactions', function (Blueprint $table) {
            $table->dropIndex(['fund_id', 'for_month']);
            $table->dropColumn('for_month');
        });
    }
};
