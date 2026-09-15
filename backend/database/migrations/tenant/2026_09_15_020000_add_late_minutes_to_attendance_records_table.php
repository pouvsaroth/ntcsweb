<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. How many minutes late a student was, recorded only when a
 * roster entry is marked LATE — see RecordAttendanceRequest. Nothing derives
 * this from a check-in timestamp; there isn't one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_minutes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('late_minutes');
        });
    }
};
