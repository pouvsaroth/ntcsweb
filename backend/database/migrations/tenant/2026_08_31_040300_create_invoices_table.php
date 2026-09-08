<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's bill. `subtotal`/`total`/`paid_amount`/`balance` are always
 * server-computed (see InvoiceService/PaymentService) — never accepted as
 * request input — from the invoice's own items and payments, so a client can
 * never hand the API a total that doesn't match what's actually on it.
 *
 * No soft-delete: a financial record is never removed, only moved to
 * CANCELLED or VOID (see InvoiceStatus) with who/when/why recorded alongside
 * it — deleting the row would destroy exactly the trail that matters most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 32);
            // No DB-level foreign key: `students` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('student_id');

            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('DRAFT');

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');

            $table->text('notes')->nullable();

            // Terminal-state bookkeeping, shared by CANCELLED and VOID alike
            // — which one applies is already recorded in `status`.
            $table->text('cancellation_reason')->nullable();
            // No DB-level foreign key on either of these: `users` hasn't
            // moved to a per-tenant database yet, and a cross-database
            // foreign key isn't possible in Postgres regardless.
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('invoice_number');
            $table->index('student_id');
            $table->index('status');
            $table->index('invoice_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
