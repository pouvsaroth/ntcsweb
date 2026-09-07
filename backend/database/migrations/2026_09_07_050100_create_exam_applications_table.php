<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own self-submitted application to sit an exam for one of
 * their own enrollments — see ExamApplication's docblock. `fee_amount`/
 * `fee_currency` are a snapshot of the tenant's `exam_fee_amount`/
 * `default_currency` at submission time (see Tenant's own migration), not a
 * live reference, so a later school-wide fee change never rewrites an
 * already-submitted application. `student_marked_paid_at` is the student's
 * own "I've paid" declaration; the actual Payment record, if any, is still
 * recorded by staff in Billing exactly as every other payment is today —
 * see the ExamApplicationService docblock for why this app doesn't yet
 * automate that confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->date('exam_date');
            $table->time('exam_time');
            $table->string('table_no', 20);
            $table->decimal('fee_amount', 10, 2);
            $table->string('fee_currency', 3);
            $table->timestamp('student_marked_paid_at');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The student's own "my exam applications" list and the admin
            // queue's "pending ones" filter are the two query shapes this
            // exists for.
            $table->index(['tenant_id', 'student_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_applications');
    }
};
