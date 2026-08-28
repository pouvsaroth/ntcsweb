<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A "class" here is a scheduled teaching group — a section
 * students enroll into, taught by one teacher in one room (e.g. "Excel
 * Basics — Evening Batch 12"). Deliberately not linked to a program/course/
 * subject table yet: those don't exist in the schema until Academic
 * Management (programs, subjects, courses) lands, and a class must be usable
 * standalone until then. Adding that link later is one nullable foreign key,
 * not a redesign.
 *
 * The model class is named `SchoolClass`, not `Class` — `class` is a reserved
 * word in PHP and cannot name a class at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Nulled, not cascaded: losing a teacher or room assignment must
            // not delete the class and its enrollment/attendance history.
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();

            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active'); // upcoming | active | completed | cancelled

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'teacher_id']);
            $table->index(['tenant_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
