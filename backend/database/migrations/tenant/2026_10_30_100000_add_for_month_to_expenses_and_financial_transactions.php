<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Account > Expense / Income "For month": the month an expense
 * or income belongs to, which can differ from the day the money moved (paid
 * today for September). Stored as that month's first day. Existing rows take
 * the month of their own date. Reports still count by date.
 * Also an expense's currency (financial_transactions already has one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->date('for_month')->nullable()->after('expense_date');
            // USD | KHR. Empty on older rows = the school's default currency.
            $table->string('currency', 3)->nullable()->after('amount');
            $table->index('for_month');
        });
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->date('for_month')->nullable()->after('transaction_date');
            $table->index(['type', 'for_month']);
        });

        $db = DB::connection($this->getConnection());
        $db->statement("update expenses set for_month = date_trunc('month', expense_date)::date where for_month is null");
        $db->statement("update financial_transactions set for_month = date_trunc('month', transaction_date)::date where for_month is null");
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropIndex(['type', 'for_month']);
            $table->dropColumn('for_month');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['for_month']);
            $table->dropColumn(['for_month', 'currency']);
        });
    }
};
