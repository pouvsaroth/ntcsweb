<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School setup, stage 1 (docs/academics/school-setup.md):
 *
 *  - academic_terms: the terms / semesters of an academic year (Semester 1,
 *    Semester 2, Term 3 …), each with its own dates.
 *  - grade_levels: Grade 1–12 (or any level the school adds), grouped into an
 *    education stage (primary, lower secondary, upper secondary …) and put in
 *    order — the order is what "the next grade" means when promoting.
 *  - classes get the grade level and academic year they belong to (both
 *    optional, so course classes keep working as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['academic_year_id', 'name']);
        });

        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 100);
            // kindergarten | primary | lower_secondary | upper_secondary | other
            $table->string('stage', 30)->nullable();
            $table->unsignedSmallInteger('level_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
            $table->index('level_order');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('grade_level_id')->nullable()->after('academic_program_id')->constrained('grade_levels')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->after('grade_level_id')->constrained('academic_years')->nullOnDelete();
        });

        $db = DB::connection();
        foreach (['academic_terms', 'grade_levels'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropConstrainedForeignId('grade_level_id');
        });
        Schema::dropIfExists('grade_levels');
        Schema::dropIfExists('academic_terms');
    }
};
