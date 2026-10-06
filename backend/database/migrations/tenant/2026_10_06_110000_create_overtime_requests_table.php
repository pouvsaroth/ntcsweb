<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Attendance & Time > Overtime: a staff member's claim for extra time
 * worked on one date, decided in E-Approvals (see OvertimeRequest and
 * DocumentType::OVERTIME_REQUEST).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('minutes');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            // Users live in the central database — no DB-level foreign keys.
            $table->unsignedBigInteger('requested_by');
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
