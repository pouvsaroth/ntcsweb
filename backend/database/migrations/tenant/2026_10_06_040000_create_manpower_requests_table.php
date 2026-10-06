<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Recruitment > Manpower request: a department asks for people to be
 * hired — how many, for which job, by when, and why. It goes through
 * E-Approvals (see ManpowerRequest and DocumentType::MANPOWER_REQUEST);
 * once approved, HR opens a job for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manpower_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('job_title');
            $table->unsignedInteger('headcount')->default(1);
            $table->string('employment_type', 20);
            $table->date('needed_by')->nullable();
            $table->text('reason');
            $table->text('requirements')->nullable();

            // Users live in the central database — no DB-level foreign key
            // (same as leave_requests.decided_by).
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 20)->default('pending');
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manpower_requests');
    }
};
