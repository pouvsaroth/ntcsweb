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

            // No DB-level foreign key: `students`/`classes` haven't moved to
            // a per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('class_id');

            $table->date('enrolled_at');
            $table->string('status', 20)->default('active'); // active | completed | dropped

            $table->timestamps();

            // A student enrolls in a given class at most once.
            $table->unique(['student_id', 'class_id']);

            // "roster of this class" and "this student's classes" — the two
            // directions every enrollment query goes.
            $table->index(['class_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
