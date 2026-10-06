<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Recruitment > Interview / Interview evaluation:
 *
 * - interviews: one meeting with an applicant (a round), when, how, where.
 * - interview_interviewers: who sits on it — user accounts (central
 *   database, so no DB-level foreign key, same as approval_group_members).
 * - interview_evaluations: each interviewer's scores and recommendation,
 *   one per interviewer per interview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('mode', 20);
            $table->string('location', 500)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('interview_interviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->unique(['interview_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('interview_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->unsignedBigInteger('evaluator_id');
            $table->json('scores');
            $table->decimal('overall_score', 3, 2);
            $table->string('recommendation', 20);
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'evaluator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_evaluations');
        Schema::dropIfExists('interview_interviewers');
        Schema::dropIfExists('interviews');
    }
};
