<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A physical table/seat within a Classroom — e.g. "Table 1"
 * through "Table 10" in "Classroom A". Cascade-deletes with its classroom
 * (a table is meaningless without the room it's in), same as LookupValue
 * cascades with its LookupCategory. See the `enrollments.table_id` migration
 * for how this feeds the "which student sits where" seat assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_tables', function (Blueprint $table) {
            $table->id();

            // No DB-level foreign key: `classrooms` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('classroom_id');
            $table->string('name');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['classroom_id', 'name']);
            $table->index('classroom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_tables');
    }
};
