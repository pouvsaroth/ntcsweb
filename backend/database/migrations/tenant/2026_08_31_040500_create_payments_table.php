<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single payment against an invoice. `payment_number` doubles as the
 * receipt number (RCPT-2026-000001) — a receipt is just this row rendered
 * as a PDF (see ReceiptPdfService), not a separate stored entity, so there
 * is no `receipts` table.
 *
 * `student_id` is denormalized off the invoice on purpose (matches the
 * relationship diagram: Student hasMany Payments directly, not only through
 * Invoice) — it never changes independently of the invoice's own
 * student_id, so this is safe redundancy for query speed, not a second
 * source of truth.
 *
 * CANCELLED/REFUNDED are real terminal states (see PaymentStatus), not a
 * deleted row — PaymentService recomputes the parent invoice's paid_amount/
 * balance/status whenever a payment's status changes, in the same
 * transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 32);
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            // No DB-level foreign key: `students` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('student_id');

            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 32);
            $table->string('status', 20)->default('COMPLETED');
            $table->date('payment_date');
            $table->string('reference_number', 100)->nullable();
            // No DB-level foreign key on either of these: `users` hasn't
            // moved to a per-tenant database yet, and a cross-database
            // foreign key isn't possible in Postgres regardless.
            $table->unsignedBigInteger('received_by')->nullable();
            $table->text('notes')->nullable();

            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique('payment_number');
            $table->index('invoice_id');
            $table->index('student_id');
            $table->index('payment_date');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
