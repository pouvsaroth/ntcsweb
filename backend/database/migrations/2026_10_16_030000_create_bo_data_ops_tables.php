<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3 of the back office — data ops. See docs/backoffice/stage-3-data-ops.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per backup run. The file lives on the `backups` disk at
        // `path`; a second copy, when switched on, on PLATFORM_BACKUP_COPY_DISK.
        Schema::create('bo_backups', function (Blueprint $table) {
            $table->id();
            $table->string('database', 100);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('trigger', 20); // manual | scheduled
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('status', 20)->default('running'); // running | ok | failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->text('copy_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['database', 'status', 'finished_at']);
        });

        // The platform's one schedule. A single row, id = 1.
        Schema::create('bo_backup_schedule', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->json('days'); // ["mon", …, "sun"]
            $table->string('at', 5)->default('02:00'); // HH:MM, Asia/Phnom_Penh
            $table->unsignedSmallInteger('keep_days')->default(14);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        // Migrate / create-database runs, one row per school per run.
        Schema::create('bo_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch'); // one "Migrate all" press = one batch
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20); // migrate | create
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | running | ok | failed
            $table->unsignedInteger('applied')->nullable(); // migrations newly applied
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        // "Is it still running?" — the scheduler tick writes its time here.
        Schema::create('bo_heartbeats', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->timestamp('beat_at');
        });

        DB::table('bo_backup_schedule')->insert([
            'id' => 1,
            'enabled' => true,
            'days' => json_encode(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']),
            'at' => '02:00',
            'keep_days' => 14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_heartbeats');
        Schema::dropIfExists('bo_migration_runs');
        Schema::dropIfExists('bo_backup_schedule');
        Schema::dropIfExists('bo_backups');
    }
};
