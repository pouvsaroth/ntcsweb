<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School setup, stage 3 (docs/academics/school-setup.md): the school
 * calendar's own days — school opening and closing, exam days, events,
 * meetings. Holidays are not here: the calendar shows the one Holidays list
 * (HRM > Time & Attendance > Holidays), which already stops student
 * attendance on those days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('title');
            // opening | closing | exam | event | meeting | other
            $table->string('type', 20)->default('event');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'school_calendar_events');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_calendar_events');
    }
};
