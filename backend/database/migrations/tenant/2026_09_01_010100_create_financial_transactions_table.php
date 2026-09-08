<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The general ledger — one row per posting, always touching
 * exactly two accounts (`debit_account_id`/`credit_account_id`) for the same
 * `amount`, so "total debit = total credit" holds by construction. This is a
 * deliberately simpler alternative to a multi-line journal_entries/
 * journal_entry_lines schema — see FinancialTransaction's docblock for why
 * that's the right level of complexity for this application today, and how
 * this can still evolve into one later without data loss.
 *
 * `reference_type`/`reference_id` point back at the Payment or Expense that
 * caused this posting (nullable — a manual adjustment/transfer has none).
 * `reverses_transaction_id` links a reversal back to the original it undoes;
 * see FinancialTransactionService::reverse(). Never physically deleted or
 * edited once posted — a correction is always a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();

            $table->string('transaction_number', 32);
            $table->date('transaction_date');
            $table->string('type', 20);

            // No DB-level foreign key on either of these: `accounts` hasn't
            // moved to a per-tenant database yet, and a cross-database
            // foreign key isn't possible in Postgres regardless.
            $table->unsignedBigInteger('debit_account_id');
            $table->unsignedBigInteger('credit_account_id');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');

            $table->text('description')->nullable();
            $table->nullableMorphs('reference');
            // `financial_transactions` moves to this same per-tenant
            // database, so this self-reference stays a real foreign key.
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->string('status', 20)->default('POSTED');

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->unique('transaction_number');
            $table->index(['type', 'transaction_date']);
            $table->index('debit_account_id');
            $table->index('credit_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
