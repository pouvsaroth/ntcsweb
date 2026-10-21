<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Teacher (docs/hrm/teachers.md). A teacher is a Staff member whose
 * position carries the Teacher role; their profile and employment stay on
 * Staff. These are the teacher-only parts:
 *
 *  - teacher_qualifications: degrees, certificates and skills.
 *  - teacher_subjects: the subjects (Books) a teacher specialises in.
 *  - teacher_assignments: a teacher on a class for a subject and year, with
 *    weekly hours (also makes them a teacher of the class: class_teachers).
 *  - classes.homeroom_staff_id: the homeroom teacher of a class.
 *  - teacher_unavailabilities: weekly hours or dates a teacher cannot teach.
 *  - teacher_substitutions: who covers a class on a day a teacher is away.
 *  - teacher_documents: private files (contract, certificates, ID …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // degree | certificate | skill
            $table->string('type', 20)->default('degree');
            // high_school | diploma | bachelor | master | doctorate | other (degrees only)
            $table->string('level', 20)->nullable();
            $table->string('title');
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('teacher_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'book_id']);
        });

        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->timestamps();

            $table->index('staff_id');
            $table->index('class_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('homeroom_staff_id')->nullable()->after('academic_year_id')->constrained('staff')->nullOnDelete();
        });

        Schema::create('teacher_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // weekly: day_of_week + times; dates: from_date..to_date (times optional)
            $table->string('kind', 10)->default('weekly');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('teacher_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('absent_staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('substitute_staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('substitute_staff_id');
        });

        Schema::create('teacher_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // contract | certificate | id_card | cv | other
            $table->string('type', 20)->default('other');
            $table->string('title');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->date('expires_on')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        $db = DB::connection();
        foreach (['teacher_qualifications', 'teacher_subjects', 'teacher_assignments', 'teacher_unavailabilities', 'teacher_substitutions', 'teacher_documents'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_documents');
        Schema::dropIfExists('teacher_substitutions');
        Schema::dropIfExists('teacher_unavailabilities');
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('homeroom_staff_id');
        });
        Schema::dropIfExists('teacher_assignments');
        Schema::dropIfExists('teacher_subjects');
        Schema::dropIfExists('teacher_qualifications');
    }
};
