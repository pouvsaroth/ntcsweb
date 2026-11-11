<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Students > Registration date (students.enrollment_date): old students
 * that never had one get the day their record was created (school time).
 */
return new class extends Migration
{
    public function up(): void
    {
        $day = DB::connection()->getDriverName() === 'pgsql'
            ? "((created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Phnom_Penh')::date"
            : 'DATE(created_at)';

        DB::table('students')
            ->whereNull('enrollment_date')
            ->whereNotNull('created_at')
            ->update(['enrollment_date' => DB::raw($day)]);
    }

    public function down(): void
    {
        // Nothing to undo: the dates filled in are kept.
    }
};
