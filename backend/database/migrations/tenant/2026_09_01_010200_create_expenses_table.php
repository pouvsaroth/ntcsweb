<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A school expense, going through DRAFT/PENDING_APPROVAL/
 * APPROVED/PAID/REJECTED/CANCELLED — see ExpenseStatus and ExpenseService.
 * `account_id` is which EXPENSE-type Chart-of-Accounts entry this charges
 * (e.g. "5300 Electricity") — never a hard-coded category string.
 * `cash_account_id` (which ASSET/cash-or-bank account paid it) is only set
 * once PAID, at which point ExpenseService posts the matching
 * FinancialTransaction. Never physically deleted — see the model docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->string('expense_number', 32);
            $table->date('expense_date');

            // No DB-level foreign key on either of these: `accounts` hasn't
            // moved to a per-tenant database yet, and a cross-database
            // foreign key isn't possible in Postgres regardless.
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('cash_account_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 32)->nullable();

            $table->string('vendor')->nullable();
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('PENDING_APPROVAL');

            // No DB-level foreign key on any of these three: `users` hasn't
            // moved to a per-tenant database yet, and a cross-database
            // foreign key isn't possible in Postgres regardless.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique('expense_number');
            $table->index(['status', 'expense_date']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
