<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Start study date: the day the student begins studying. Before it the
 * enrollment is Not started; on that day it turns Studying (see
 * EnrollmentService::startDue()). Old enrollments start on their
 * enrollment date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->date('start_study_on')->nullable()->after('enrolled_at');
            $table->index(['status', 'start_study_on']);
        });

        DB::table('enrollments')->whereNull('start_study_on')->update(['start_study_on' => DB::raw('enrolled_at')]);
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex(['status', 'start_study_on']);
            $table->dropColumn('start_study_on');
        });
    }
};
