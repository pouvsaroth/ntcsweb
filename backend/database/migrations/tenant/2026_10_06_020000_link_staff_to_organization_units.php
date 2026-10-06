<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nests HRM > Organization Management's lists (a department sits in a
 * branch, a team in a department) and places each staff member in them:
 * branch, department, team, job grade, job level and the colleague they
 * report to. All optional. nullOnDelete is only a backstop — the delete
 * endpoints refuse a unit that is still in use (see
 * OrganizationUnitController::inUse() and DepartmentController::destroy()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('id')->constrained('departments')->nullOnDelete();
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('job_grades')->nullOnDelete();
            $table->foreignId('job_level_id')->nullable()->constrained('job_levels')->nullOnDelete();
            $table->foreignId('reports_to_staff_id')->nullable()->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to_staff_id');
            $table->dropConstrainedForeignId('job_level_id');
            $table->dropConstrainedForeignId('job_grade_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
