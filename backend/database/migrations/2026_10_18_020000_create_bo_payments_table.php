<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5b (docs/backoffice/stage-5-billing.md): money a school has paid,
 * recorded by staff. It records; it never charges. A mistake is voided with a
 * reason, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('bo_school_subscriptions')->restrictOnDelete();
            $table->string('receipt_number', 20)->unique(); // R-2026-0001
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3); // USD | KHR
            $table->unsignedSmallInteger('periods')->default(1);
            $table->string('method', 20); // cash | bank | khqr | cheque | other
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            // The subscription's coverage this payment added; null for a plan with no end.
            $table->date('covered_from')->nullable();
            $table->date('covered_to')->nullable();
            $table->date('end_before')->nullable(); // the end date before this payment
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'paid_on']);
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_payments');
    }
};
