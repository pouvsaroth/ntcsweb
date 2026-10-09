<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A shift's hours per weekday (HRM > Attendance & Time >
 * Shift — Day / From / To rows, like a class schedule): day_of_week is ISO,
 * 1 = Monday … 7 = Sunday, same as class_schedules. One row per day.
 *
 * shifts.start_time/end_time stay as the shift's fallback hours — used on a
 * weekday it has no row for, and by every shift created before this table
 * (no rows at all), so those keep working unchanged. See Shift::timesOn().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->unique(['shift_id', 'day_of_week']);
        });

        DB::statement(
            'ALTER TABLE shift_days ADD CONSTRAINT shift_days_day_of_week_check CHECK (day_of_week BETWEEN 1 AND 7)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_days');
    }
};
