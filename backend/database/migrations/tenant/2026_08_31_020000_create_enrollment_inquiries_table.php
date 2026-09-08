<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visitor's "Register" submission from the public website — a lead, not a
 * Student/Enrollment record. Turning one into an actual enrolled student
 * still goes through the existing admin Student-creation flow; this table
 * only captures the initial interest so the school has something to follow
 * up on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            // No DB-level foreign key: `programs` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('program_id')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_inquiries');
    }
};
