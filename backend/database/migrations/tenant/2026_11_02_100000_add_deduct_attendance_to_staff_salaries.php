<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Payroll > Basic salary "Deduct for attendance": off for
 * a staff member whose pay shouldn't follow Attendance & Time (not on the
 * check-in system, ...) — payroll then takes nothing off for absence, unpaid
 * leave, late or early leave. On for every existing salary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_salaries', function (Blueprint $table) {
            $table->boolean('deduct_attendance')->default(true)->after('salary_structure_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff_salaries', function (Blueprint $table) {
            $table->dropColumn('deduct_attendance');
        });
    }
};
