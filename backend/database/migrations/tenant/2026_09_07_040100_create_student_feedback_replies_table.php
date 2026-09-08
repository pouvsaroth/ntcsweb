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
 *
 * Lives in the school's own database, same as its parent — see that
 * migration's docblock. `user_id` has no DB-level foreign key for the same
 * reason `student_id` doesn't there: `users` hasn't moved to a per-tenant
 * database yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_feedback_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_feedback_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_feedback_replies');
    }
};
