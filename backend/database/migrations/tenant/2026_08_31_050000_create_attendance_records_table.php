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

            // `enrollments` moves to this same per-tenant database, so this
            // one stays a real foreign key.
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();

            // No DB-level foreign key on these two: `classes`/`students`
            // haven't moved to a per-tenant database yet, and a
            // cross-database foreign key isn't possible in Postgres
            // regardless.
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');

            $table->date('date');
            $table->string('status', 20)->default('PRESENT');
            $table->text('remarks')->nullable();

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            // One attendance record per student per class per day — taking
            // attendance twice for the same day updates it, never duplicates.
            $table->unique(['enrollment_id', 'date']);

            // "roster of this class on this date" and "this student's
            // history" — the two directions every query goes, same pairing
            // Enrollment's own indexes use.
            $table->index(['class_id', 'date']);
            $table->index(['student_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
