<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own self-submitted request or comment, about the school in
 * general or about a specific teacher — see StudentFeedback's docblock.
 * Deliberately separate from LeaveRequest: there is no approve/reject
 * decision here, just a `status` indicator flipped by StudentFeedbackReply
 * (see that migration), never written directly by this table's own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // request | comment
            $table->string('topic', 20); // school | teacher
            $table->foreignId('teacher_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('subject', 150);
            $table->text('message');
            $table->string('status', 20)->default('open'); // open | replied
            $table->timestamps();
            $table->softDeletes();

            // The student's own "my feedback" list and the admin queue's
            // "open ones" filter are the two query shapes this exists for.
            $table->index(['tenant_id', 'student_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_feedbacks');
    }
};
