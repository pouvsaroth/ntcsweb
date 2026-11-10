<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Loan > Owe: money lent to customers (they owe the owner).
 * Like Borrowings, the other way round: paid back monthly over `term_months`
 * (none / flat / reducing interest, see BorrowingSchedule) or, without
 * months, flexibly. Each payment is split into principal and interest.
 * Ledger: lent = Loans Receivable <- Cash/Bank; principal back = the
 * reverse; interest = income (Loan Interest Income).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no', 20)->nullable()->unique(); // L00001, from the id
            $table->foreignId('loan_customer_id')->constrained('loan_customers')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->date('lent_on');
            $table->unsignedBigInteger('cash_account_id')->nullable(); // where the money came from
            $table->unsignedBigInteger('lent_transaction_id')->nullable();
            $table->string('interest_type', 20)->default('none'); // none | flat | reducing
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->string('interest_period', 10)->default('month'); // month | year
            $table->unsignedSmallInteger('term_months')->nullable(); // empty = flexible
            $table->date('first_payment_on')->nullable();
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('active'); // active | paid_off | cancelled
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'lent_on']);
        });

        Schema::create('customer_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_loan_id')->constrained('customer_loans')->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('principal', 15, 2)->default(0);
            $table->decimal('interest', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('principal_transaction_id')->nullable();
            $table->unsignedBigInteger('interest_transaction_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['customer_loan_id', 'paid_on']);
            $table->index('paid_on');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'customer_loans');
        SchoolTables::secure($db, 'customer_loan_payments');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_loan_payments');
        Schema::dropIfExists('customer_loans');
    }
};
