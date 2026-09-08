<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own self-submitted leave/permission request — deliberately a
 * separate table from attendance_records, not a new status bolted onto it:
 * that table is written only as a whole-class batch by a teacher/staff (see
 * AttendanceService), keyed uniquely on (enrollment_id, date), with no
 * concept of "pending," a reason, or an attachment. A leave request lives
 * here on its own status machine; only once an admin approves it does
 * LeaveRequestService write the corresponding attendance_records rows
 * (status EXCUSED) via the existing, unmodified AttendanceService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            // No DB-level foreign key: `students`/`users` haven't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('student_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The student's own "my requests" list and the admin queue's
            // "pending ones" filter are the two query shapes this exists for.
            $table->index('student_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
