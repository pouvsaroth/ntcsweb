<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counter per (tenant, prefix) — see StudentIdGenerator. Deliberately its
 * own table, not a key inside `tenants.settings`: generating a Student ID
 * increments this row under `SELECT ... FOR UPDATE`, which a jsonb
 * read-modify-write (how every other tenant setting is stored) cannot do
 * safely under concurrent requests.
 *
 * Keeping a prefix's row forever (never deleting it when the tenant switches
 * away from that prefix) is what lets switching back later resume the same
 * count instead of restarting or colliding — see StudentIdGeneratorTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_id_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('prefix', 20);
            $table->unsignedInteger('next_number')->default(1);

            $table->timestamps();

            $table->unique(['tenant_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_id_sequences');
    }
};
