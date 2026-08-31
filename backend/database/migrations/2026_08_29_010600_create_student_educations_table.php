<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Mirrors the legacy `t_school_student_education` table — a
 * student's prior schooling history, one row per school previously
 * attended. `end_date` nullable means "still attending" there.
 *
 * No soft deletes — see student_guardians' migration for why (same
 * child-of-Student, replace-the-whole-list pattern as StudentController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_educations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('school_name', 200);
            $table->string('address', 225);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('skill', 200);
            $table->string('detail', 500);

            $table->timestamps();

            // "this student's education history" — the only query pattern this table serves.
            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_educations');
    }
};
