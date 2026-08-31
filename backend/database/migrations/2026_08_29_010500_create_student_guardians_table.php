<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Mirrors the legacy `t_school_student_guardian` table — a
 * student can have more than one guardian (father, mother, other), so this
 * is its own table, not flat columns on `students`.
 *
 * `guardian_type` is free text (the legacy column is an `int` with no
 * documented lookup table available for migration) rather than a fixed
 * enum — the school types the relationship directly ("Father", "Aunt", ...)
 * instead of this platform assuming a code mapping it can't verify.
 *
 * No soft deletes: mirrors Enrollment, a similar child-of-Student record —
 * the parent Student's soft-delete is what stays recoverable, and the admin
 * UI replaces a student's whole guardian list on every save rather than
 * editing rows individually (see StudentController::update()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('guardian_name', 100);
            $table->string('guardian_type', 50);
            $table->string('address', 200)->nullable();
            $table->string('phone', 50);
            $table->string('email', 50)->nullable();
            $table->string('remark', 500)->nullable();

            $table->timestamps();

            // "this student's guardians" — the only query pattern this table serves.
            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
    }
};
