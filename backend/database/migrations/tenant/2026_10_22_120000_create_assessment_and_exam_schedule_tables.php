<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examination, gradebook & assessment — step 7a (docs/academics/gradebook.md):
 * how each grade level is assessed, and the exam schedule.
 *
 * - assessment_schemes: one per grade level (null = the school's default);
 *   mode `score` (weighted components, bands) or `competency` (levels per
 *   criterion). Nothing about the Ministry's rules is hard-coded.
 * - assessment_components: what is scored in a term and how much it counts.
 * - grade_bands: letter / label from a minimum percent.
 * - competency_criteria / competency_levels: the competency mode's grid.
 * - exam_schedules: when and where each subject's exam is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->cascadeOnDelete();
            $table->string('mode', 20)->default('score'); // score | competency
            $table->string('name', 150);
            // The scale results are shown on (100, 50, 10…); entries keep their own max.
            $table->decimal('max_score', 6, 2)->default(100);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('grade_level_id');
        });

        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_km', 100)->nullable();
            $table->string('kind', 20)->default('other'); // monthly | quiz | assignment | midterm | exam | project | other
            $table->decimal('max_score', 6, 2)->default(100);
            $table->decimal('weight', 5, 2); // % of the term result
            $table->unsignedTinyInteger('occurrences')->default(1); // per term, averaged
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('assessment_scheme_id');
        });

        Schema::create('grade_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            $table->string('letter', 5);
            $table->string('label', 50)->nullable();
            $table->string('label_km', 50)->nullable();
            $table->decimal('min_percent', 5, 2);
            $table->timestamps();

            $table->unique(['assessment_scheme_id', 'letter']);
        });

        Schema::create('competency_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            // Null = every subject.
            $table->foreignId('book_id')->nullable()->constrained('books')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('name_km', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('assessment_scheme_id');
        });

        Schema::create('competency_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('label', 50);
            $table->string('label_km', 50)->nullable();
            $table->decimal('points', 5, 2);
            $table->boolean('is_passing')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['assessment_scheme_id', 'code']);
        });

        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('assessment_component_id')->nullable()->constrained('assessment_components')->nullOnDelete();
            // Every class of a grade, or one class.
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->foreignId('invigilator_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['exam_date', 'start_time']);
            $table->index('academic_year_id');
        });

        $db = DB::connection();
        foreach (['assessment_schemes', 'assessment_components', 'grade_bands', 'competency_criteria', 'competency_levels', 'exam_schedules'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('competency_levels');
        Schema::dropIfExists('competency_criteria');
        Schema::dropIfExists('grade_bands');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('assessment_schemes');
    }
};
