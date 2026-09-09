<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Staff status transition, append-only — mirrors
 * `enrollment_status_histories` exactly, plus a `requested_date` column: the
 * date the change was requested, distinct from `effective_date` (when it
 * actually takes effect). See StaffController::changeStatus(), the only
 * writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->date('requested_date')->nullable();
            $table->date('effective_date')->nullable();

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_status_histories');
    }
};
