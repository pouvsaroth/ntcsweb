<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A configurable tree of physical locations (Main Campus >
 * Administration Building > Computer Lab 1) — deliberately separate from
 * `classrooms` (a flat, scheduling-focused table with no hierarchy above the
 * room itself), but `classroom_id` optionally links a location that IS also
 * a schedulable room, so the two aren't duplicated by hand for that case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20)->default('ROOM'); // CAMPUS | BUILDING | FLOOR | ROOM | OTHER
            $table->foreignId('parent_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            // No DB-level foreign key: `classrooms` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('classroom_id')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_locations');
    }
};
