<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Loan > Savings: money customers keep with the owner.
 * One account per customer per currency (USD and KHR are never summed).
 * Each deposit / withdrawal is an entry posted to the ledger
 * (Cash/Bank <-> Customer Savings). `type` leaves room for `interest` later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 20)->nullable()->unique(); // S00001, from the id
            $table->foreignId('loan_customer_id')->constrained('loan_customers')->restrictOnDelete();
            $table->string('currency', 3); // USD | KHR
            $table->date('opened_on');
            $table->string('status', 10)->default('open'); // open | closed
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['loan_customer_id', 'currency']);
            $table->index('status');
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_account_id')->constrained('savings_accounts')->cascadeOnDelete();
            $table->string('type', 20); // deposit | withdrawal | interest (later)
            $table->decimal('amount', 15, 2);
            $table->date('transacted_on');
            $table->string('remark', 500)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable(); // the ledger entry
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['savings_account_id', 'transacted_on']);
            $table->index('transacted_on');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'savings_accounts');
        SchoolTables::secure($db, 'savings_transactions');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_accounts');
    }
};
