<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reason/effective-date behind an enrollment's *current* status —
 * denormalized here for quick display (list/detail views), while the full
 * history of every status transition lives in enrollment_status_histories.
 * Only meaningful for a status that actually requires them (Abandoned/
 * Stopped/Suspended today — see EnrollmentService::changeStatus()); null for
 * every routine status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->text('status_reason')->nullable()->after('status');
            $table->date('status_effective_date')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_effective_date']);
        });
    }
};
