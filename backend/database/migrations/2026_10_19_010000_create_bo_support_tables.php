<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 6b (docs/backoffice/stage-6-support.md): read-only support views of a
 * school. Every view is a row here, with the pages it loaded; the ticket that
 * opens it is stored hashed and works once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('ticket_hash', 64)->unique(); // sha256 of the one-time ticket
            $table->timestamp('ticket_expires_at');
            $table->timestamp('ticket_used_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 20)->nullable(); // staff | timeout | sign_out
            $table->foreignId('ended_by_staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('bo_support_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_session_id')->constrained('bo_support_sessions')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status')->nullable();
            $table->timestamp('at');

            $table->index(['support_session_id', 'at']);
        });

        Schema::create('bo_support_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_support_session_events');
        Schema::dropIfExists('bo_support_sessions');
        Schema::dropIfExists('bo_support_settings');
    }
};
