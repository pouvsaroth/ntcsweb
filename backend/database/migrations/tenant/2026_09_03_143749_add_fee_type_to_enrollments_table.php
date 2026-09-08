<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the course package's 5 fee tiers (monthly/term/video/
 * monthly_online/term_online) this enrollment was actually billed under —
 * `fee` itself already snapshots the resolved amount, this just records
 * which tier it came from, for reporting. Nullable: the legacy book-based
 * enrollment path has no fee-tier concept at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('fee_type', 20)->nullable()->after('fee');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('fee_type');
        });
    }
};
