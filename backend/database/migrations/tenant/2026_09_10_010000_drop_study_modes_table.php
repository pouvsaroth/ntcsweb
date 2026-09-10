<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folded into the STUDY_MODE base-data lookup category (see BaseDataSeeder)
 * — a standalone table was never needed for a two-value, rarely-changing
 * list once the app already had a generic configurable-list mechanism.
 * Nothing else references `study_modes`/`study_mode_id` anymore
 * (Enrollment's own `study_mode_id` was dropped earlier — see
 * 2026_09_09_010000_drop_legacy_columns_and_add_code_to_enrollments_table),
 * so this is a clean drop with no data migration needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('study_modes');
    }

    public function down(): void
    {
        Schema::create('study_modes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code');
        });
    }
};
