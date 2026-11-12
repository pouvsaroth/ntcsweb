<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data ops > Databases > Use for a school: a restored "Other" database put
 * in place of a school's live database (DatabaseSwapper). One row per swap,
 * with its steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_database_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('live_database', 63);     // the school's database name (kept)
            $table->string('restored_database', 63); // the Other database put in its place
            $table->string('kept_as', 63);           // the live one's name afterwards
            $table->foreignId('backup_id')->nullable()->constrained('bo_backups')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | running | done | failed
            $table->string('previous_status', 20)->nullable(); // the school's status before it was suspended
            $table->json('steps')->nullable(); // [{step, status, message, ms}]
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_database_swaps');
    }
};
