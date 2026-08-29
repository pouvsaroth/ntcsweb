<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide reference data (like `permissions`), never tenant-owned:
 * Cambodia's official administrative hierarchy — province > district >
 * commune > village — sourced from the NIS/NCDD gazetteer (via the MIT-licensed
 * seanghay/pumi-js dataset). Seeded by CambodiaGeographySeeder, not by this
 * migration — schema and data are kept separate, same as permissions/roles.
 *
 * `code` is the real NIS code string (e.g. province "01", village
 * "01020101") — kept as a plain unique column, not the primary key, so every
 * FK in this app stays a fast integer join. `students.village_code` stores
 * this same code as free text (deliberately *not* a hard FK — see that
 * migration's own reasoning: this platform isn't Cambodia-only, and a
 * legacy-imported code should never be blocked by a constraint it can't
 * satisfy). The admin UI's cascading selects are what keep new data
 * consistent; the lack of a DB constraint is what keeps old/foreign data
 * insertable at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name_km');
            $table->string('name_latin');
            $table->string('unit_km', 20);
            $table->string('unit_latin', 20);
            $table->string('unit_en', 20);
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name_km');
            $table->string('name_latin');
            $table->string('unit_km', 20);
            $table->string('unit_latin', 20);
            $table->string('unit_en', 20);
            $table->timestamps();
        });

        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name_km');
            $table->string('name_latin');
            $table->string('unit_km', 20);
            $table->string('unit_latin', 20);
            $table->string('unit_en', 20);
            $table->timestamps();
        });

        Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commune_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name_km');
            $table->string('name_latin');
            $table->string('unit_km', 20);
            $table->string('unit_latin', 20);
            $table->string('unit_en', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('communes');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('provinces');
    }
};
