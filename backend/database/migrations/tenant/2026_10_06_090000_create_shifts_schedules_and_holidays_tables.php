<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Attendance & Time's set-up — what staff attendance is measured
 * against:
 *
 * - shifts: working hours (start/end, unpaid break) and how much lateness /
 *   leaving early is tolerated before it counts.
 * - work_schedules: a weekly pattern — which shift (or none = day off) on
 *   each weekday; each staff member follows one (staff.work_schedule_id),
 *   or the default one.
 * - holidays: dates nobody is expected at work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0);
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('monday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('tuesday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('wednesday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('thursday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('friday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('saturday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('sunday_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->nullOnDelete();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_schedule_id');
        });
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('shifts');
    }
};
