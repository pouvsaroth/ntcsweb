<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Accounting > Borrowings: money borrowed from companies and
 * people. Each loan says who it is borrowed for (the Expense for list:
 * Newtech, Flexi, Family...). The repayment schedule is worked out from the
 * loan, not stored (BorrowingSchedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lenders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('company'); // company | person
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('borrowings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lender_id')->constrained('lenders')->restrictOnDelete();
            $table->foreignId('expense_entity_id')->constrained('expense_entities')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->date('received_on');
            // The Cash/Bank account the money went into (no DB foreign key, same as expenses).
            $table->unsignedBigInteger('cash_account_id')->nullable();
            $table->string('interest_type', 20)->default('none'); // none | flat | reducing
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->string('interest_period', 10)->default('month'); // month | year
            $table->unsignedSmallInteger('term_months');
            $table->date('first_payment_on');
            $table->text('purpose')->nullable();
            $table->string('contract_path')->nullable();
            $table->string('contract_name')->nullable();
            $table->string('status', 20)->default('active'); // active | paid_off | cancelled
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_on']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'lenders');
        SchoolTables::secure($db, 'borrowings');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowings');
        Schema::dropIfExists('lenders');
    }
};
