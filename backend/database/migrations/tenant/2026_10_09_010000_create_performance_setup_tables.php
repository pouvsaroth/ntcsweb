<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Performance Management's set-up (stage 1).
 *
 * - performance_settings: one row — how the final score is weighted:
 *   KPIs + goals + the manager's assessment (the self-assessment is shown
 *   but doesn't count). Ratings everywhere are 1–5.
 * - performance_cycles: a review period (e.g. "2026 annual"), with the
 *   evaluation form its reviews use and when each assessment is due.
 * - kpis: the KPI library — what's measured, its unit and target, for
 *   everyone or one department / position.
 * - evaluation_forms (+ questions): the questions a review asks, each
 *   rated 1–5 or answered in words, in sections.
 * - performance_goals: a staff member's goals for a cycle (or open-ended),
 *   with progress, and the self / manager rating given during the review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('kpi_weight')->default(40);
            $table->unsignedTinyInteger('goal_weight')->default(30);
            $table->unsignedTinyInteger('manager_weight')->default(30);
            $table->timestamps();
        });

        Schema::create('evaluation_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('evaluation_form_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_form_id')->constrained('evaluation_forms')->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->text('question');
            $table->string('type', 20)->default('rating'); // rating (1–5) | text
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['evaluation_form_id', 'sort_order']);
        });

        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('self_assessment_due')->nullable();
            $table->date('manager_assessment_due')->nullable();
            $table->foreignId('evaluation_form_id')->nullable()->constrained('evaluation_forms')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft | active | closed
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // How it's measured, e.g. "Pass rate of the teacher's classes".
            $table->string('measurement')->nullable();
            $table->string('unit', 20)->nullable(); // %, students, hours, ...
            $table->decimal('target', 15, 2)->nullable();
            $table->boolean('higher_is_better')->default(true);
            $table->unsignedTinyInteger('default_weight')->default(0);
            // For everyone, or one department / position (suggested for them on a review).
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->constrained('performance_cycles')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            // Its share of the goals part of the score; 0 for all = equal shares.
            $table->unsignedTinyInteger('weight')->default(0);
            $table->unsignedTinyInteger('progress')->default(0); // 0–100 %
            $table->string('status', 20)->default('not_started'); // not_started | in_progress | completed | cancelled
            // Given during the review (stage 2), 1–5.
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'performance_cycle_id']);
        });

        DB::table('performance_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('kpis');
        Schema::dropIfExists('performance_cycles');
        Schema::dropIfExists('evaluation_form_questions');
        Schema::dropIfExists('evaluation_forms');
        Schema::dropIfExists('performance_settings');
    }
};
