<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs StaffIdGenerator — identical shape and purpose to
 * `student_id_sequences` (see that migration's docblock): one row per
 * prefix, `SELECT ... FOR UPDATE` locked in StaffIdGenerator::next() for the
 * actual concurrency guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_id_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique('prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_id_sequences');
    }
};
