<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The complete business-lifecycle narrative of one asset —
 * "what happened to it," answered in plain language, one row per event,
 * never edited or deleted. Deliberately separate from `audit_logs` (which
 * answers "who did it in the system") — see AssetHistory's own docblock for
 * the full reasoning. Written exclusively by AssetHistoryRecorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->string('event_type', 30);
            $table->text('description');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_history');
    }
};
