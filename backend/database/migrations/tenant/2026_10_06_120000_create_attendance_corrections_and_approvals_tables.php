<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Attendance & Time's last two tabs:
 *
 * - attendance_corrections: a request to fix one day's check-in/out ("I
 *   forgot to check in"), decided in E-Approvals; approving it writes the
 *   times onto the day (see AttendanceCorrectionService).
 * - attendance_approvals: a manager's sign-off of one staff member's month.
 *   While it exists, that month's attendance is locked (see
 *   StaffAttendanceService::assertOpen()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('check_in', 5)->nullable();
            $table->string('check_out', 5)->nullable();
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

        Schema::create('attendance_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->char('month', 7);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('approved_by');
            $table->timestamps();

            $table->unique(['staff_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_approvals');
        Schema::dropIfExists('attendance_corrections');
    }
};
