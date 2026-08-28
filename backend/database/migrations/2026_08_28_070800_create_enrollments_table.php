<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Student <-> Class, with the metadata that makes it more than
 * a pivot: when they joined and whether they're still active in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();

            $table->date('enrolled_at');
            $table->string('status', 20)->default('active'); // active | completed | dropped

            $table->timestamps();

            // A student enrolls in a given class at most once.
            $table->unique(['student_id', 'class_id']);

            // "roster of this class" and "this student's classes" — the two
            // directions every enrollment query goes.
            $table->index(['tenant_id', 'class_id', 'status']);
            $table->index(['tenant_id', 'student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
