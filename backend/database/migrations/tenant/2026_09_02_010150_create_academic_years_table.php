<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real academic year (e.g. "2026", 2026-01-01 to 2026-12-31) that a
 * Program Offering is scheduled under. Previously left as a plain string on
 * `program_offerings` deliberately, as a seam for exactly this table to be
 * added later without a redesign -- see the Program Offerings migration.
 *
 * Lives in each school's own database (see AcademicYear's own docblock) —
 * this is the proof-of-concept for that pattern, so no `tenant_id` column:
 * every row in this table already belongs to whichever school's database
 * it's in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
