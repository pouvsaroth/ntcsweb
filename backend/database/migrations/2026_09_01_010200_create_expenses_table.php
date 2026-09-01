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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('expense_number', 32);
            $table->date('expense_date');

            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 32)->nullable();

            $table->string('vendor')->nullable();
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('PENDING_APPROVAL');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'status', 'expense_date']);
            $table->index(['tenant_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
