<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Accounting > Borrowings > Repayments: each payment back to a
 * lender, split into principal (lowers Loans Payable) and interest (a paid
 * expense tagged with the loan's Borrowed for). `receipt_transaction_id` is
 * the ledger entry for the money coming in (Cash/Bank ← Loans Payable).
 * No DB foreign keys to accounts / financial_transactions / expenses, same as
 * expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('receipt_transaction_id')->nullable()->after('cash_account_id');
        });

        Schema::create('borrowing_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrowing_id')->constrained('borrowings')->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('interest', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('principal_transaction_id')->nullable();
            $table->unsignedBigInteger('interest_expense_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['borrowing_id', 'paid_on']);
            $table->index('paid_on');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'borrowing_repayments');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowing_repayments');
        Schema::table('borrowings', function (Blueprint $table) {
            $table->dropColumn('receipt_transaction_id');
        });
    }
};
