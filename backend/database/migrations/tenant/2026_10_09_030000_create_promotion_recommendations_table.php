<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Performance Management > Promotion recommendation (stage 3) — a
 * proposed new position / job grade / job level and, optionally, a new
 * basic salary for a staff member, from a date — usually from a completed
 * review. Pending (approval, through an Approval Flow if the school set
 * one) → approved → applied to the staff record and Payroll once its date
 * comes; or rejected / cancelled. The "from" columns keep what they had
 * when it was recommended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('performance_review_id')->nullable()->constrained('performance_reviews')->nullOnDelete();
            $table->foreignId('from_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('from_job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->foreignId('to_job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->foreignId('from_job_level_id')->nullable()->constrained('job_levels')->nullOnDelete();
            $table->foreignId('to_job_level_id')->nullable()->constrained('job_levels')->nullOnDelete();
            // In the staff member's salary currency.
            $table->decimal('from_basic_salary', 15, 2)->nullable();
            $table->decimal('new_basic_salary', 15, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->date('effective_date');
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | applied | rejected | cancelled
            // Who recommended it — the "requester" ApprovalFlow tells about each step.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_date']);
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_recommendations');
    }
};
