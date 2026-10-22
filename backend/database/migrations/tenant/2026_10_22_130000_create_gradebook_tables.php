<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examination, gradebook & assessment — step 7b (docs/academics/gradebook.md):
 * scores entered per class + subject + term, and what they add up to.
 *
 * - result_sheets: one per class + subject + term, with the assessment setup
 *   it was entered under; status draft → pending → approved | returned (7c).
 * - grade_entries: one student's score (or absent) for one component and one
 *   time it is given — or, in competency mode, a level for one criterion.
 * - subject_results: calculated on every save: percent, the score on the
 *   setup's scale, band / level, passed, rank in the class.
 * - assessment_schemes.absent_counts_as_zero: whether an absent counts as 0
 *   or is left out of the average (the school decides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_schemes', function (Blueprint $table) {
            $table->boolean('absent_counts_as_zero')->default(true)->after('decimals');
        });

        Schema::create('result_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->foreignId('assessment_scheme_id')->nullable()->constrained('assessment_schemes')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft | pending | approved | returned
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'book_id', 'academic_term_id']);
            $table->index(['academic_term_id', 'status']);
        });

        Schema::create('grade_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained('result_sheets')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // Score mode
            $table->foreignId('assessment_component_id')->nullable()->constrained('assessment_components')->restrictOnDelete();
            $table->unsignedTinyInteger('occurrence')->default(1);
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('absent')->default(false);
            // Competency mode
            $table->foreignId('competency_criterion_id')->nullable()->constrained('competency_criteria')->restrictOnDelete();
            $table->foreignId('competency_level_id')->nullable()->constrained('competency_levels')->restrictOnDelete();
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['result_sheet_id', 'student_id', 'assessment_component_id', 'occurrence'], 'grade_entries_score_unique');
            $table->unique(['result_sheet_id', 'student_id', 'competency_criterion_id'], 'grade_entries_criterion_unique');
            $table->index('student_id');
        });

        Schema::create('subject_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained('result_sheets')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('band', 10)->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->boolean('complete')->default(false);
            $table->timestamps();

            $table->unique(['result_sheet_id', 'student_id']);
            $table->index('student_id');
        });

        $db = DB::connection();
        foreach (['result_sheets', 'grade_entries', 'subject_results'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_results');
        Schema::dropIfExists('grade_entries');
        Schema::dropIfExists('result_sheets');
        Schema::table('assessment_schemes', function (Blueprint $table) {
            $table->dropColumn('absent_counts_as_zero');
        });
    }
};
