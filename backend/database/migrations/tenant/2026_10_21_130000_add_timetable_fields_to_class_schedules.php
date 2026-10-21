<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Timetable (docs/academics/timetable.md). The timetable is the classes' own
 * weekly times (class_schedules, set on the class form) — not a second
 * schedule. Each time can now say its subject, its teacher and its room (when
 * different from the class's room); left empty, the class's teachers and room
 * apply as before.
 *
 * schedule_change_requests: a teacher (or staff) asks to move a weekly time —
 * another day/time, room or teacher; approving it changes the timetable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_schedules', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable()->after('end_time')->constrained('books')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->after('book_id')->constrained('staff')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->after('staff_id')->constrained('classrooms')->nullOnDelete();
            $table->string('note')->nullable()->after('classroom_id');
            $table->index('staff_id');
        });

        Schema::create('schedule_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedTinyInteger('new_day_of_week');
            $table->time('new_start_time');
            $table->time('new_end_time');
            $table->foreignId('new_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->foreignId('new_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('reason');
            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'schedule_change_requests');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_change_requests');
        Schema::table('class_schedules', function (Blueprint $table) {
            $table->dropIndex(['staff_id']);
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropConstrainedForeignId('staff_id');
            $table->dropConstrainedForeignId('book_id');
            $table->dropColumn('note');
        });
    }
};
