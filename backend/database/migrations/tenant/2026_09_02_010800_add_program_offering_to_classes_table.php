<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nullable FK `classes`' own migration docblock already promised
 * ("adding that link later is one nullable foreign key, not a redesign").
 * Superseded by academic_program_id two migrations later — `program_offerings`
 * itself never existed anywhere but the central database, and was long since
 * dropped there, so this column's life is brief on a fresh tenant database
 * too. No DB-level foreign key: cross-database regardless, and doubly so
 * once `program_offerings` stopped existing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedBigInteger('program_offering_id')->nullable()->after('classroom_id');

            $table->index('program_offering_id');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('program_offering_id');
        });
    }
};
