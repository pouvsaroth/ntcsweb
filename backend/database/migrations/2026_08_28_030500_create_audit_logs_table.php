<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned table (tenant_id NULL == platform-level event, e.g. a super admin
 * creating a school).
 *
 * Append-only: rows are written once and never updated, so there is no
 * updated_at column and no soft delete. This is the highest-write table in the
 * system after attendance.
 *
 * Scale note: when this table becomes large the natural next step is RANGE
 * partitioning on created_at (monthly) plus a retention policy that drops old
 * partitions. Deliberately not done yet — see docs/database.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Nullable so the log survives the actor being deleted, and so that
            // system/queue-generated events can be recorded with no actor.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('event', 64); // auth.login, students.updated, ...

            $table->string('auditable_type', 191)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The audit log screen: one school's history, newest first.
            $table->index(['tenant_id', 'created_at']);

            // "history of this one record"
            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'audit_logs_auditable_index');

            // "what did this user do?"
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
