<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing audit_logs table (see create_audit_logs_table) rather
 * than replacing it — `event` already carries a free-form "module.action"
 * string (auth.login, auth.logout, ...) and stays as-is for backward
 * compatibility. These new columns give the admin-facing read API (filters,
 * list, detail view) clean, indexed, human-facing fields instead of having
 * every consumer re-parse `event`.
 *
 * All nullable: existing rows (auth.login/auth.logout, written before this
 * migration) simply have no value here rather than needing a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 32)->nullable()->after('event');
            $table->string('module', 64)->nullable()->after('action');
            $table->text('description')->nullable()->after('new_values');
            $table->string('request_method', 10)->nullable()->after('user_agent');
            $table->string('request_url', 2048)->nullable()->after('request_method');

            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['tenant_id', 'module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'action', 'created_at']);
            $table->dropIndex(['tenant_id', 'module', 'created_at']);
            $table->dropColumn(['action', 'module', 'description', 'request_method', 'request_url']);
        });
    }
};
