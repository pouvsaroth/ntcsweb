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
 *
 * Lives in the school's own database (see BelongsToTenant's docblock) — no
 * `tenant_id` column. `student_id`/`enrollment_id`/`decided_by` stay plain
 * bigints with no DB-level foreign key: Student/Enrollment/User haven't
 * moved to a per-tenant database yet, and a cross-database foreign key
 * isn't possible in Postgres regardless — see ExamApplication's own
 * relations, which still resolve correctly as ordinary separate queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->date('exam_date');
            $table->time('exam_time');
            $table->string('table_no', 20);
            $table->decimal('fee_amount', 10, 2);
            $table->string('fee_currency', 3);
            $table->timestamp('student_marked_paid_at');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The student's own "my exam applications" list and the admin
            // queue's "pending ones" filter are the two query shapes this
            // exists for.
            $table->index('student_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_applications');
    }
};
