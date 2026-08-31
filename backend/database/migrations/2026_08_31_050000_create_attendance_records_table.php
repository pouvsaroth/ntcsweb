<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One row per student per class per calendar date — see
 * AttendanceRecord's docblock for why there is no separate "class session"
 * table: a class's recurring weekly pattern already lives in
 * `class_schedules`, and attendance is simply taken against a specific date
 * rather than a materialized occurrence row.
 *
 * `student_id`/`class_id` are denormalized off `enrollment_id` (same reason
 * Payment denormalizes `student_id` off its Invoice) so both directions this
 * feature is queried from — "this class's roster on this date" and "this
 * student's attendance history" — are single-table queries with no join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->date('date');
            $table->string('status', 20)->default('PRESENT');
            $table->text('remarks')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            // One attendance record per student per class per day — taking
            // attendance twice for the same day updates it, never duplicates.
            $table->unique(['enrollment_id', 'date']);

            // "roster of this class on this date" and "this student's
            // history" — the two directions every query goes, same pairing
            // Enrollment's own indexes use.
            $table->index(['tenant_id', 'class_id', 'date']);
            $table->index(['tenant_id', 'student_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
