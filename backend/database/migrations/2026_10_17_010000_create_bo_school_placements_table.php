<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 4b (docs/backoffice/stage-4-database-placement.md): where each school's
 * data lives. No row = dedicated (its own database on the main server) — every
 * school that existed before this table, so nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_school_placements', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->string('mode', 20); // shared | dedicated | separate
            $table->string('database', 100);
            // Separate server only. The password is encrypted (model cast) and
            // never leaves the server in an API response.
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('sslmode', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_school_placements');
    }
};
