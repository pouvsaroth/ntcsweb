<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable — every existing classroom keeps its free-text
 * `location` untouched; this just lets a classroom optionally point at a
 * real Building row instead (or alongside it, e.g. "Room 204" + "Main
 * Building"). `nullOnDelete`, matching every other room-scoped FK on this
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('location')
                ->constrained('buildings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });
    }
};
