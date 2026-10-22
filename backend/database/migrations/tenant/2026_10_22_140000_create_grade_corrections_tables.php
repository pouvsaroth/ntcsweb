<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examination, gradebook & assessment — step 7c (docs/academics/gradebook.md):
 * result approval and grade corrections, both through eApprovals.
 *
 * - result_sheets.requested_by: who submitted the sheet (the eApprovals
 *   "requester", told of each approved step).
 * - grade_corrections: a requested change to one cell of an approved sheet,
 *   with its reason; pending → approved | rejected.
 * - grade_entry_histories: every change made to an approved sheet's cell
 *   (old → new), why, by whom, through which correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->after('status');
        });

        Schema::create('grade_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained('result_sheets')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('assessment_component_id')->nullable()->constrained('assessment_components')->restrictOnDelete();
            $table->unsignedTinyInteger('occurrence')->default(1);
            $table->foreignId('competency_criterion_id')->nullable()->constrained('competency_criteria')->restrictOnDelete();
            $table->decimal('old_score', 6, 2)->nullable();
            $table->boolean('old_absent')->default(false);
            $table->unsignedBigInteger('old_level_id')->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->boolean('new_absent')->default(false);
            $table->unsignedBigInteger('new_level_id')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('result_sheet_id');
        });

        Schema::create('grade_entry_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained('result_sheets')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedBigInteger('assessment_component_id')->nullable();
            $table->unsignedTinyInteger('occurrence')->default(1);
            $table->unsignedBigInteger('competency_criterion_id')->nullable();
            $table->decimal('old_score', 6, 2)->nullable();
            $table->boolean('old_absent')->default(false);
            $table->unsignedBigInteger('old_level_id')->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->boolean('new_absent')->default(false);
            $table->unsignedBigInteger('new_level_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('grade_correction_id')->nullable()->constrained('grade_corrections')->nullOnDelete();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at');

            $table->index(['result_sheet_id', 'student_id']);
        });

        $db = DB::connection();
        foreach (['grade_corrections', 'grade_entry_histories'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_entry_histories');
        Schema::dropIfExists('grade_corrections');
        Schema::table('result_sheets', function (Blueprint $table) {
            $table->dropColumn('requested_by');
        });
    }
};
