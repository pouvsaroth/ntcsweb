<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 4d (docs/backoffice/stage-4-database-placement.md): moving a school's
 * data between placements — out of the shared database, or between its own
 * database and a separate server. One row per move, with its steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_school_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('from_mode', 20);
            $table->string('to_mode', 20);
            // Both placements as they were/will be; a separate server's
            // password inside is encrypted (model cast), never sent back.
            $table->text('from_placement');
            $table->text('to_placement');
            $table->string('status', 20)->default('queued'); // queued | running | done | failed
            $table->string('previous_status', 20)->nullable(); // the school's status before it was suspended
            $table->foreignId('backup_id')->nullable()->constrained('bo_backups')->nullOnDelete();
            $table->json('steps')->nullable(); // [{step, status, message, ms}]
            $table->json('counts')->nullable(); // rows per table, source vs copy
            $table->text('error')->nullable();
            // The old copy is kept after a move until staff remove it.
            $table->timestamp('old_copy_removed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_school_moves');
    }
};
