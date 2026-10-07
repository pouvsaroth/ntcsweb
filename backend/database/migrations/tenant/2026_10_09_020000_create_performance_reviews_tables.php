<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Performance Management's reviews (stage 2).
 *
 * - performance_reviews: one staff member's review in a cycle, by their
 *   manager (their Reports-to when launched; HR can change it). Self
 *   assessment → manager assessment → completed, with the scores (1–5).
 * - performance_review_kpis: the review's own copy of the KPIs for them —
 *   target, weight, the actual result, and the self / manager rating.
 * - performance_review_answers: the review's own copy of the cycle form's
 *   questions with the self / manager answers — copied so editing the form
 *   later doesn't change reviews already under way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('reviewer_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('status', 30)->default('self_assessment'); // self_assessment | manager_assessment | completed
            $table->text('self_comment')->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->text('manager_comment')->nullable();
            // Used for the manager part when the form has no 1–5 questions.
            $table->unsignedTinyInteger('manager_overall_rating')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();
            // 1–5, worked out on completion (see PerformanceScorer).
            $table->decimal('kpi_score', 4, 2)->nullable();
            $table->decimal('goal_score', 4, 2)->nullable();
            $table->decimal('manager_score', 4, 2)->nullable();
            $table->decimal('self_score', 4, 2)->nullable();
            $table->decimal('final_score', 4, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['performance_cycle_id', 'staff_id']);
            $table->index(['reviewer_staff_id', 'status']);
        });

        Schema::create('performance_review_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->foreignId('kpi_id')->nullable()->constrained('kpis')->nullOnDelete();
            $table->string('name');
            $table->string('measurement')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('target', 15, 2)->nullable();
            $table->boolean('higher_is_better')->default(true);
            $table->unsignedTinyInteger('weight')->default(0);
            $table->decimal('actual', 15, 2)->nullable();
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('performance_review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->foreignId('evaluation_form_question_id')->nullable()->constrained('evaluation_form_questions')->nullOnDelete();
            $table->string('section')->nullable();
            $table->text('question');
            $table->string('type', 20); // rating | text
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->text('self_answer')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('manager_answer')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_review_answers');
        Schema::dropIfExists('performance_review_kpis');
        Schema::dropIfExists('performance_reviews');
    }
};
