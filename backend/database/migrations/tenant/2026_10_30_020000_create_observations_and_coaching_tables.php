<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Professional Development, step 2 (docs/hrm/professional-development.md):
 *
 *  - observation_forms / observation_form_criteria: the school's classroom
 *    observation forms, criteria rated 1–5 stars, grouped by section.
 *  - classroom_observations: a teacher observed in a class and subject by an
 *    observer, on a form; strengths, areas to improve, next steps, and the
 *    teacher's acknowledgement and comment.
 *  - classroom_observation_ratings: the form's criteria copied onto the
 *    observation (so later form edits don't change it), each with a rating.
 *  - coachings / coaching_sessions: a coach or mentor paired with a staff
 *    member around a goal (optionally following an observation), and the
 *    sessions they hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('observation_form_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_form_id')->constrained('observation_forms')->cascadeOnDelete();
            $table->string('section', 200)->nullable();
            $table->text('criterion');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['observation_form_id', 'sort_order']);
        });

        Schema::create('classroom_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('observer_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('observation_form_id')->nullable()->constrained('observation_forms')->nullOnDelete();
            $table->string('form_name', 200)->nullable();
            $table->date('observed_on');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('focus', 500)->nullable();
            // scheduled | completed | cancelled
            $table->string('status', 20)->default('scheduled');
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('next_steps')->nullable();
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('teacher_comment')->nullable();
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'observed_on']);
            $table->index('observer_staff_id');
        });

        Schema::create('classroom_observation_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_observation_id')->constrained('classroom_observations')->cascadeOnDelete();
            $table->string('section', 200)->nullable();
            $table->text('criterion');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['classroom_observation_id', 'sort_order']);
        });

        Schema::create('coachings', function (Blueprint $table) {
            $table->id();
            // coaching | mentoring
            $table->string('type', 20)->default('coaching');
            $table->foreignId('coach_staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('classroom_observation_id')->nullable()->constrained('classroom_observations')->nullOnDelete();
            $table->string('goal', 500);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // active | completed | cancelled
            $table->string('status', 20)->default('active');
            $table->text('outcome')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('staff_id');
            $table->index('coach_staff_id');
        });

        Schema::create('coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coaching_id')->constrained('coachings')->cascadeOnDelete();
            $table->date('session_date');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('topic', 300);
            $table->text('notes')->nullable();
            $table->text('action_items')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['coaching_id', 'session_date']);
        });

        $db = DB::connection();
        foreach (['observation_forms', 'observation_form_criteria', 'classroom_observations', 'classroom_observation_ratings', 'coachings', 'coaching_sessions'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_sessions');
        Schema::dropIfExists('coachings');
        Schema::dropIfExists('classroom_observation_ratings');
        Schema::dropIfExists('classroom_observations');
        Schema::dropIfExists('observation_form_criteria');
        Schema::dropIfExists('observation_forms');
    }
};
