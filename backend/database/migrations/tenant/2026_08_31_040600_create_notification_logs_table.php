<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to send an invoice/receipt out through a channel (Telegram,
 * email, ...) — one row per attempt, so resending creates a new row rather
 * than overwriting the last result (see InvoiceNotificationService). This is
 * what lets an administrator see whether a notification actually went out,
 * independent of whether the invoice/payment itself succeeded — a failed
 * send never rolls back the financial record it's attached to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            // No DB-level foreign key: `students` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('channel', 20);
            $table->string('recipient', 191);
            $table->string('type', 40);
            $table->string('status', 20)->default('PENDING');
            $table->text('message')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
