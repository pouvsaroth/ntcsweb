<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a staff member file a leave request through the same flow a student
 * already does — see LeaveRequestService's docblock for why the row is
 * "owned" by exactly one of `student_id`/`staff_id`, never both, enforced at
 * the application layer (LeaveRequestService::submit()) rather than a DB
 * constraint, matching this table's existing style. A staff-owned request
 * never reaches LeaveRequestService::applyToAttendance() — staff have no
 * enrollments/attendance to mark Excused, so approving one is just a status
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            // No DB-level foreign key, matching student_id above — see that
            // column's own comment.
            $table->unsignedBigInteger('staff_id')->nullable()->after('student_id');
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('staff_id');
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
        });
    }
};
