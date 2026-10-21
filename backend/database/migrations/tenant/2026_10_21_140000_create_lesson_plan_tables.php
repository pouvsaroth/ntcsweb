<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic > Lesson plans (docs/academics/lesson-plans.md).
 *
 *  - lesson_plans: a teacher's plan for one lesson of a class — subject,
 *    curriculum lesson (School setup > Curriculum), date, objectives,
 *    materials, activities, homework — reviewed (draft → submitted →
 *    approved / returned).
 *  - lesson_plan_files: its attachments (PDF, slides …), private.
 *  - teaching_logs: what was actually taught on a day (done / partly / not),
 *    linked to the plan and/or the curriculum lesson — the source of
 *    curriculum progress and of the delay report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('curriculum_lesson_id')->nullable()->constrained('curriculum_lessons')->nullOnDelete();
            $table->date('planned_date');
            $table->string('title');
            $table->text('objectives')->nullable();
            $table->text('materials')->nullable();
            $table->text('activities')->nullable();
            $table->text('homework')->nullable();
            $table->date('homework_due')->nullable();
            // draft | submitted | approved | returned
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'planned_date']);
            $table->index(['class_id', 'planned_date']);
            $table->index('status');
        });

        Schema::create('lesson_plan_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_plan_id')->constrained('lesson_plans')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('lesson_plan_id');
        });

        Schema::create('teaching_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('curriculum_lesson_id')->nullable()->constrained('curriculum_lessons')->nullOnDelete();
            $table->foreignId('lesson_plan_id')->nullable()->constrained('lesson_plans')->nullOnDelete();
            $table->date('taught_on');
            $table->string('title');
            // done | partial | not_done
            $table->string('status', 20)->default('done');
            $table->text('homework')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'taught_on']);
            $table->index(['staff_id', 'taught_on']);
            $table->index('lesson_plan_id');
        });

        $db = DB::connection();
        foreach (['lesson_plans', 'lesson_plan_files', 'teaching_logs'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_logs');
        Schema::dropIfExists('lesson_plan_files');
        Schema::dropIfExists('lesson_plans');
    }
};
