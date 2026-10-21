<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School setup, stage 4 (docs/academics/school-setup.md).
 *
 *  - promotion_rules: what a student needs to go up a grade — a minimum
 *    average score, a minimum attendance and at most so many failed subjects
 *    (a subject is failed below its pass mark). Every check is optional. One
 *    rule per grade level; the rule with no grade level is the school's
 *    default for every grade without its own.
 *  - student_promotions: what Promote students decided for one enrollment —
 *    promoted, repeated or graduated, the figures it saw, and the new
 *    enrollment it made. One per enrollment, so a class is never promoted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->cascadeOnDelete();
            $table->decimal('min_average', 5, 2)->nullable();
            $table->decimal('min_attendance', 5, 2)->nullable();
            $table->unsignedSmallInteger('max_failed_subjects')->nullable();
            $table->decimal('pass_mark', 5, 2)->default(50);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('grade_level_id');
        });

        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('from_enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('from_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('to_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('to_enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            // promoted | repeated | graduated
            $table->string('result', 20);
            $table->boolean('met_rule')->default(false);
            $table->decimal('average', 5, 2)->nullable();
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('failed_subjects')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();

            $table->unique('from_enrollment_id');
            $table->index('student_id');
        });

        $db = DB::connection();
        foreach (['promotion_rules', 'student_promotions'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotions');
        Schema::dropIfExists('promotion_rules');
    }
};
