<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examination, gradebook & assessment — step 7e (docs/academics/gradebook.md):
 * Remedial Teaching.
 *
 * - remedial_sessions: extra teaching for one class + subject (in a term),
 *   who taught it, when, the topic.
 * - remedial_session_students: the students taken, their percent in the
 *   subject when added, whether they came and the outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remedial_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('topic');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'academic_term_id', 'session_date']);
        });

        Schema::create('remedial_session_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remedial_session_id')->constrained('remedial_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('percent_before', 6, 2)->nullable();
            $table->boolean('attended')->nullable();
            $table->string('outcome', 20)->nullable(); // improved | no_change | needs_more
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->unique(['remedial_session_id', 'student_id']);
            $table->index('student_id');
        });

        $db = DB::connection();
        foreach (['remedial_sessions', 'remedial_session_students'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('remedial_session_students');
        Schema::dropIfExists('remedial_sessions');
    }
};
