<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message in a StudentFeedback thread — flat and append-only, same shape
 * as project_task_comments, not a decision field: either the student (on
 * their own feedback) or an authorized staff/admin can post here. The first
 * reply from someone other than the feedback's own student flips the
 * parent's `status` to "replied" (see StudentFeedback::addReply()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_feedback_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'student_feedback_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_feedback_replies');
    }
};
