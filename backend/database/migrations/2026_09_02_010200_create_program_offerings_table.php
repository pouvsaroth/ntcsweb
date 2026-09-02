<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program being offered under a specific study mode for a given academic
 * year — e.g. "English - Full Time - 2026". `academic_year_id` links to a
 * real AcademicYear row (see that migration for why this used to be a
 * plain string).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->foreignId('study_mode_id')->constrained('study_modes')->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('status', 20)->default('active'); // active | closed
            $table->timestamps();

            $table->unique(['tenant_id', 'academic_program_id', 'study_mode_id', 'academic_year_id'], 'program_offerings_unique_combo');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_offerings');
    }
};
