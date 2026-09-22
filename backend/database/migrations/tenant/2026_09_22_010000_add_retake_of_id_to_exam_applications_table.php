<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a "make_up" (retake) exam application back to the scored
 * application it was generated from — see ExamScoreService::record()'s
 * `make_up` entry flag and ExamApplication::STATUS_MAKE_UP. Nullable and
 * self-referencing: every ordinary application (draft/pending/approved/
 * rejected/not_exam) leaves this null; only a system-created make-up row
 * ever sets it. Lets the Grades tab know a make-up has already been
 * requested for a given scored row (checking the box twice never creates a
 * second retake — see ExamScoreEntryResource's `has_make_up`), and the
 * Make-up Exam tab trace a row back to its original exam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->foreignId('retake_of_id')->nullable()->after('book_id')->constrained('exam_applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retake_of_id');
        });
    }
};
