<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Professional Development, step 3 (docs/hrm/professional-development.md):
 *
 *  - development_plans: one staff member's development plan for a period,
 *    with an optional reviewer who follows it up; draft until shared.
 *  - development_plan_goals: what they will develop, how, the support they
 *    get (optionally a training or a coaching), a target date, and progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('reviewer_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('title', 200);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // draft | active | completed | cancelled
            $table->string('status', 20)->default('draft');
            $table->text('summary')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'status']);
        });

        Schema::create('development_plan_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_plan_id')->constrained('development_plans')->cascadeOnDelete();
            $table->string('title', 300);
            $table->string('area', 120)->nullable();
            $table->text('actions')->nullable();
            $table->text('support')->nullable();
            $table->foreignId('training_id')->nullable()->constrained('trainings')->nullOnDelete();
            $table->foreignId('coaching_id')->nullable()->constrained('coachings')->nullOnDelete();
            $table->date('target_date')->nullable();
            // not_started | in_progress | done | dropped
            $table->string('status', 20)->default('not_started');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('staff_note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['development_plan_id', 'sort_order']);
        });

        $db = DB::connection($this->getConnection());
        foreach (['development_plans', 'development_plan_goals'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('development_plan_goals');
        Schema::dropIfExists('development_plans');
    }
};
