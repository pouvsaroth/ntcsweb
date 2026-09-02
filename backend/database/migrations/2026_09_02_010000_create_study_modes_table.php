<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned, configurable — Full Time / Part Time and anything else a
 * school later wants (Weekend, Online, ...), never hard-coded strings
 * scattered through the app. Same "real CRUD table, not a Support enum"
 * choice as Asset Categories/Locations/Repair Shops in this same app,
 * because the spec explicitly wants administrators to add more later —
 * see StudyModeService::ensureDefaults() for how FULL_TIME/PART_TIME get
 * seeded into a fresh tenant without a provisioning-pipeline change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_modes');
    }
};
