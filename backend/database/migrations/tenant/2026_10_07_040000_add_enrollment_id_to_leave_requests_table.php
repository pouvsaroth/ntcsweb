<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The course (enrollment) a student's leave request is for — picked on the
 * form, so approving it marks only that course's class Excused (see
 * LeaveRequestService::applyToAttendance()). Nullable: staff requests never
 * have one, and student requests filed before this column existed still
 * cover every active enrollment, as they did then. No DB-level foreign key,
 * matching student_id/staff_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('enrollment_id')->nullable()->after('staff_id');
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('enrollment_id');
        });
    }
};
