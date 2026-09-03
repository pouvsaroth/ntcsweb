<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Program Offering feature (Program + Study Mode + Academic Year) is
 * removed — classes now link straight to an Academic Program (see the
 * migration just before this one). Nothing else references this table by
 * the time this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('program_offerings');
    }

    public function down(): void
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
};
