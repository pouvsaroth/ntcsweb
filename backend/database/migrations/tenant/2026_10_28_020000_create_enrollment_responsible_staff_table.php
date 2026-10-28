<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class > Students > Responsible Staff: which staff member looks after a
 * student in a class, from a date (and optionally to a date). Kept as a
 * history: a new assignment closes the open one the day before it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_responsible_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_responsible_staff');
    }
};
