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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20)->default('ROOM'); // CAMPUS | BUILDING | FLOOR | ROOM | OTHER
            $table->foreignId('parent_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_locations');
    }
};
