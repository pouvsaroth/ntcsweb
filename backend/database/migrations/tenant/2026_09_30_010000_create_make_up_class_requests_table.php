<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own self-submitted make-up class request (ស្នើសុំរៀនសង) — same
 * pending/approved/rejected workflow as leave_requests (see that
 * migration's docblock), but student-only, so `student_id` is required
 * rather than nullable. `enrollment_id` is the course (course package +
 * class) the make-up is for — always one of the student's own active
 * enrollments at submit time. No DB-level foreign key on
 * `student_id`/`enrollment_id`/`decided_by`, same style as leave_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('make_up_class_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->time('from_time');
            $table->time('to_time');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected

            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('student_id');
            $table->index('enrollment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('make_up_class_requests');
    }
};
