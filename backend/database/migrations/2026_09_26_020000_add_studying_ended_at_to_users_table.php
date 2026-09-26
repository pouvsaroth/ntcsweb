<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the "student stops studying -> account goes inactive after N days"
 * rule (StudentAccessService). Kept on the central users row rather than the
 * tenant-side students table so login can decide without opening the
 * school's own database — the ERP domain's cross-tenant login never does.
 *
 * studying_ended_at: when the student's last Studying enrollment ended
 * (null while they still have one). auto_deactivated_at: set when the rule
 * switched the account off, so an admin re-activating it by hand isn't
 * undone again by the next daily run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('studying_ended_at')->nullable()->after('status');
            $table->timestamp('auto_deactivated_at')->nullable()->after('studying_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['studying_ended_at', 'auto_deactivated_at']);
        });
    }
};
